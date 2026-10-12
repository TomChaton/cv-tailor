<?php

namespace App\MasterCv;

/**
 * A marker the author writes into the CV to say what is not known.
 *
 * These are part of the format rather than noise in it. They are the author's
 * only way of saying "this far and no further" about a fact, so the parser
 * carries them through to the material the model selects from.
 */
enum ContentMarker: string
{
    /**
     * Unknown. Content carrying it is never selectable: a tailored CV that
     * fills a `TODO` in has invented the thing the author declined to state.
     */
    case Todo = 'todo';

    /**
     * The surrounding claim stands, but there is no detail behind it. It does
     * not block selection — the sentence is a fact — and it tells a reader of
     * the prompt that no elaboration exists to be found.
     */
    case NotRecorded = 'not_recorded';

    /**
     * Usable word for word and never reworded, because any rewording would be
     * reaching past what the author wrote down.
     */
    case DoNotElaborate = 'do_not_elaborate';

    /**
     * @return list<self>
     */
    public static function detect(string $text): array
    {
        $markers = [];

        // `TODO` is matched case-sensitively: the author writes it in capitals
        // on purpose, and "todo" inside an ordinary sentence is not a marker.
        if (preg_match('/\bTODO\b/', $text) === 1) {
            $markers[] = self::Todo;
        }

        if (preg_match('/\bnot recorded\b/i', $text) === 1) {
            $markers[] = self::NotRecorded;
        }

        if (preg_match('/\bdo not elaborate\b/i', $text) === 1) {
            $markers[] = self::DoNotElaborate;
        }

        return $markers;
    }

    /**
     * @param  list<self>  $markers
     * @return list<string>
     */
    public static function values(array $markers): array
    {
        return array_map(static fn (self $marker): string => $marker->value, $markers);
    }
}
