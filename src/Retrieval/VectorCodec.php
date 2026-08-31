<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * Packs and unpacks the embedding stored on each chunk row.
 *
 * Section 3: "stored as a compact binary blob on the chunk row". Single-precision
 * floats, little-endian, no header — 256 dimensions is 1 KB per chunk, which for
 * a few thousand chunks is a few megabytes in the table and nothing to page in.
 *
 * 'g' (little-endian float32) rather than 'f' (machine byte order) on purpose:
 * machine order would make the blobs unreadable if the database were ever moved
 * between architectures, and a silently mis-decoded vector does not error, it
 * just quietly reranks wrongly.
 */
final class VectorCodec
{
    /** @param list<float> $vector */
    public static function encode(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function decode(string $blob): array
    {
        if ($blob === '') {
            return [];
        }

        $values = unpack('g*', $blob);
        if ($values === false) {
            return [];
        }

        /** @var list<float> $list */
        $list = array_values(array_map(static fn ($v): float => (float) $v, $values));

        return $list;
    }
}
