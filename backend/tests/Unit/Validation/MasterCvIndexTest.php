<?php

namespace Tests\Unit\Validation;

use App\MasterCv\RoleKind;
use App\Validation\MasterCvIndex;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class MasterCvIndexTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_resolves_roles_items_and_skills(): void
    {
        $index = $this->index();

        $this->assertTrue($index->resolves('harlequin-transit'));
        $this->assertTrue($index->resolves('harlequin-transit.4.3'));
        $this->assertTrue($index->resolves('fathom-index'));
        $this->assertTrue($index->resolves('sql'));
    }

    public function test_an_id_the_master_cv_does_not_hold_resolves_to_nothing(): void
    {
        $this->assertFalse($this->index()->resolves('harlequin-transit.99'));
        $this->assertFalse($this->index()->isUnknown('harlequin-transit.99'));
    }

    /**
     * The CV holds the ID and the author has said the fact behind it is
     * unknown. That is a different thing from an ID that is not there, and the
     * owner needs to be told which.
     */
    public function test_content_marked_todo_is_held_and_unavailable(): void
    {
        $index = $this->index();

        $this->assertTrue($index->has('larkspur-media.2'));
        $this->assertFalse($index->resolves('larkspur-media.2'));
        $this->assertTrue($index->isUnknown('larkspur-media.2'));
    }

    /**
     * An ID's prefix is a convention; the parse is the fact.
     */
    public function test_ownership_comes_from_the_parse(): void
    {
        $index = $this->index();

        $employed = $index->ownerOf('harlequin-transit.4.1');
        $independent = $index->ownerOf('fathom-index.1.2');

        $this->assertNotNull($employed);
        $this->assertNotNull($independent);
        $this->assertSame('Harlequin Transit', $employed->employer);
        $this->assertSame(RoleKind::Employment, $employed->kind);
        $this->assertSame(RoleKind::Independent, $independent->kind);
    }

    /**
     * The skills index is a property of the document rather than of an
     * employer.
     */
    public function test_a_skill_belongs_to_no_role(): void
    {
        $this->assertNull($this->index()->ownerOf('sql'));
    }

    public function test_it_reads_the_master_cvs_own_words_for_an_item(): void
    {
        $this->assertStringContainsString('Dead-letter replay', (string) $this->index()->textOf('harlequin-transit.4.3'));
    }

    private function index(): MasterCvIndex
    {
        return new MasterCvIndex($this->parseFixture());
    }
}
