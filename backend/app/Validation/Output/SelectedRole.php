<?php

namespace App\Validation\Output;

use App\MasterCv\RoleKind;
use App\MasterCv\RoleMode;

/**
 * One role or project as the output presented it.
 *
 * `container` is which of the two lists it came back in, not what the master
 * CV says it is. The two are compared, because an independent project
 * returned under the employment list claims commercial experience nobody paid
 * for.
 */
final readonly class SelectedRole
{
    /**
     * @param  list<SelectedItem>  $items
     */
    public function __construct(
        public ?string $id,
        public string $field,
        public RoleKind $container,
        public ?RoleMode $mode,
        public array $items,
    ) {}

    /**
     * @param  array<array-key, mixed>  $selection
     */
    public static function from(array $selection, string $field, RoleKind $container): self
    {
        $id = $selection['id'] ?? null;
        $mode = $selection['mode'] ?? null;
        $items = [];

        foreach (is_array($selection['items'] ?? null) ? $selection['items'] : [] as $index => $item) {
            if (is_array($item)) {
                $items[] = SelectedItem::from($item, $field.'.items['.$index.']');
            }
        }

        return new self(
            id: is_string($id) ? $id : null,
            field: $field,
            container: $container,
            mode: is_string($mode) ? RoleMode::tryFrom($mode) : null,
            items: $items,
        );
    }

    public function isShown(): bool
    {
        return $this->mode !== RoleMode::Omitted;
    }
}
