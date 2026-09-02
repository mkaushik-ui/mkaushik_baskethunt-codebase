<?php
declare(strict_types=1);

namespace SOI\Core\Services\Revisions;

final class DiffViewer
{
    /**
     * Compare an old block array against a new block array to produce a unified diff.
     *
     * @param list<array<string,mixed>> $oldBlocks
     * @param list<array<string,mixed>> $newBlocks
     * @return list<array{status:string,block:array<string,mixed>}>
     */
    public static function diff(array $oldBlocks, array $newBlocks): array
    {
        $oldMap = [];
        foreach ($oldBlocks as $b) {
            $id = $b['id'] ?? '';
            if ($id !== '') {
                $oldMap[$id] = $b;
            }
        }
        
        $newMap = [];
        foreach ($newBlocks as $b) {
            $id = $b['id'] ?? '';
            if ($id !== '') {
                $newMap[$id] = $b;
            }
        }
        
        $diff = [];
        
        // Output deleted blocks (present in old, missing in new)
        foreach ($oldBlocks as $b) {
            $id = $b['id'] ?? '';
            if ($id !== '' && !isset($newMap[$id])) {
                $diff[] = [
                    'status' => 'deleted',
                    'block' => $b,
                ];
            }
        }
        
        // Output new or modified or unchanged blocks
        foreach ($newBlocks as $b) {
            $id = $b['id'] ?? '';
            if ($id === '' || !isset($oldMap[$id])) {
                $diff[] = [
                    'status' => 'added',
                    'block' => $b,
                ];
            } else {
                $oldBlock = $oldMap[$id];
                $oldType = $oldBlock['type'] ?? '';
                $newType = $b['type'] ?? '';
                $oldData = json_encode($oldBlock['data'] ?? []);
                $newData = json_encode($b['data'] ?? []);

                if ($oldType !== $newType || $oldData !== $newData) {
                    $diff[] = [
                        'status' => 'modified',
                        'block' => $b,
                    ];
                } else {
                    $diff[] = [
                        'status' => 'unchanged',
                        'block' => $b,
                    ];
                }
            }
        }
        
        return $diff;
    }
}
