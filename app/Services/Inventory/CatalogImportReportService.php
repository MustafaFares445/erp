<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use Illuminate\Support\Facades\Storage;
use LogicException;

final readonly class CatalogImportReportService
{
    /** @return array{result_path: string, summary_path: string} */
    public function generate(InventoryImportRun $run): array
    {
        $runId = $this->integerKey($run->getKey());
        $resultPath = sprintf('catalog-imports/results/run-%d-rows.csv', $runId);
        $summaryPath = sprintf('catalog-imports/results/run-%d-summary.csv', $runId);

        Storage::disk('local')->put($resultPath, $this->detailedCsv($run));
        Storage::disk('local')->put($summaryPath, $this->summaryCsv($run));

        return ['result_path' => $resultPath, 'summary_path' => $summaryPath];
    }

    private function detailedCsv(InventoryImportRun $run): string
    {
        $stream = $this->temporaryStream();
        fputcsv($stream, [
            'row_number',
            'status',
            'operation',
            'validation_errors',
            'runtime_error',
            'result',
            'payload',
        ]);

        foreach ($run->items()->orderBy('row_number')->cursor() as $item) {
            $this->writeItem($stream, $item);
        }

        return $this->streamContents($stream);
    }

    private function summaryCsv(InventoryImportRun $run): string
    {
        $stream = $this->temporaryStream();
        fputcsv($stream, ['field', 'value']);

        foreach ([
            'run_id' => $run->getKey(),
            'status' => $run->status->value,
            'total_rows' => $run->total_rows,
            'valid_rows' => $run->valid_rows,
            'failed_rows' => $run->failed_rows,
            'created_rows' => $run->created_rows,
            'updated_rows' => $run->updated_rows,
            'applied_rows' => $run->applied_rows,
            'rejected_rows' => $run->rejected_rows,
            'failure_message' => $run->failure_message,
            'confirmed_at' => $run->confirmed_at?->toIso8601String(),
        ] as $field => $value) {
            $this->writeRow($stream, [$field, $value]);
        }

        return $this->streamContents($stream);
    }

    /** @param resource $stream */
    private function writeItem(mixed $stream, InventoryImportItem $item): void
    {
        $this->writeRow($stream, [
            $item->row_number,
            $item->status->value,
            $item->operation,
            $this->json($item->errors),
            $item->runtime_error,
            $this->json($item->result),
            $this->json($item->payload),
        ]);
    }

    /** @return resource */
    private function temporaryStream(): mixed
    {
        $stream = @fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new LogicException('Unable to create the import report stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function streamContents(mixed $stream): string
    {
        try {
            throw_unless(
                @rewind($stream),
                LogicException::class,
                'Unable to read the import report stream.',
            );
            $contents = @stream_get_contents($stream);
            throw_unless(
                is_string($contents),
                LogicException::class,
                'Unable to read the import report stream.',
            );

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    private function json(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new LogicException('Unable to encode an import report value.');
        }

        return $json;
    }

    private function scalarValue(mixed $value): bool|float|int|string|null
    {
        if (is_string($value)) {
            return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
        }

        if (is_bool($value) || is_float($value) || is_int($value) || $value === null) {
            return $value;
        }

        return $this->json($value);
    }

    /**
     * @param  resource  $stream
     * @param  list<mixed>  $values
     */
    private function writeRow(mixed $stream, array $values): void
    {
        fputcsv($stream, array_map($this->scalarValue(...), $values));
    }

    private function integerKey(mixed $key): int
    {
        if (! is_int($key)) {
            throw new LogicException('Inventory import runs must use integer identifiers.');
        }

        return $key;
    }
}
