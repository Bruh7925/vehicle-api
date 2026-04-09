<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ImportVehicles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicles:import {file=vehicles.csv}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import vehicles from storage/app CSV or XLSX file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fileName = (string) $this->argument('file');
        $filePath = storage_path('app/'.$fileName);

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        try {
            if ($extension === 'csv') {
                [$imported, $skipped] = $this->importCsv($filePath);
            } elseif ($extension === 'xlsx') {
                [$imported, $skipped] = $this->importXlsx($filePath);
            } else {
                $this->error("Unsupported file extension: .{$extension}. Use CSV or XLSX.");

                return self::FAILURE;
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Import complete. Imported: {$imported}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function importCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open file: {$filePath}");
        }

        $rowNumber = 0;
        $imported = 0;
        $skipped = 0;
        $headerMap = [];
        $useHeaderMap = false;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($rowNumber === 1) {
                    $headerMap = $this->buildHeaderMap($row);
                    $useHeaderMap = $this->headerMatchesVehicleSchema($headerMap);
                    continue;
                }

                if (! $this->createVehicleFromRow($row, $rowNumber, $headerMap, $useHeaderMap)) {
                    $skipped++;
                    continue;
                }

                $imported++;
            }
        } finally {
            fclose($handle);
        }

        return [$imported, $skipped];
    }

    private function importXlsx(string $filePath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Unable to open file: {$filePath}");
        }

        try {
            $sharedStrings = $this->getSharedStrings($zip);
            $worksheetPath = $this->getFirstWorksheetPath($zip);
            $worksheetXml = $zip->getFromName($worksheetPath);

            if ($worksheetXml === false) {
                throw new RuntimeException("Worksheet not found in file: {$filePath}");
            }

            $worksheet = simplexml_load_string($worksheetXml);

            if (! $worksheet instanceof SimpleXMLElement || ! isset($worksheet->sheetData)) {
                throw new RuntimeException('Invalid XLSX worksheet format.');
            }

            $rowNumber = 0;
            $imported = 0;
            $skipped = 0;
            $headerMap = [];
            $useHeaderMap = false;

            foreach ($worksheet->sheetData->row as $xmlRow) {
                $rowNumber++;

                $row = $this->extractXlsxRow($xmlRow, $sharedStrings);

                if ($rowNumber === 1) {
                    $headerMap = $this->buildHeaderMap($row);
                    $useHeaderMap = $this->headerMatchesVehicleSchema($headerMap);
                    continue;
                }

                if (! $this->createVehicleFromRow($row, $rowNumber, $headerMap, $useHeaderMap)) {
                    $skipped++;
                    continue;
                }

                $imported++;
            }

            return [$imported, $skipped];
        } finally {
            $zip->close();
        }
    }

    private function createVehicleFromRow(array $row, int $rowNumber, array $headerMap, bool $useHeaderMap): bool
    {
        if ($useHeaderMap) {
            $plateNumber = $this->valueFromHeaders($row, $headerMap, [
                'platenumber',
                'plateno',
                'immatriculation',
                'matricule',
                'nmatricule',
                'numeromatricule',
                'nomatricule',
            ]);
            $marque = $this->valueFromHeaders($row, $headerMap, [
                'marque',
                'marqueettype',
                'brand',
            ]);
            $chassis = $this->valueFromHeaders($row, $headerMap, [
                'chassis',
                'numerochassis',
                'nochassis',
                'nchassis',
            ]);
            $color = $this->valueFromHeaders($row, $headerMap, [
                'color',
                'colour',
                'couleur',
            ]);
            $modelYearRaw = $this->valueFromHeaders($row, $headerMap, [
                'modelyear',
                'annee',
                'anneemodele',
                'anneedemodele',
                'year',
            ]);
        } else {
            $plateNumber = trim((string) ($row[0] ?? ''));
            $marque = trim((string) ($row[1] ?? ''));
            $chassis = trim((string) ($row[2] ?? ''));
            $color = trim((string) ($row[3] ?? ''));
            $modelYearRaw = trim((string) ($row[4] ?? ''));
        }

        if ($plateNumber === '' && $marque === '' && $chassis === '' && $color === '' && $modelYearRaw === '') {
            return false;
        }

        if ($plateNumber === '' && $marque === '' && $chassis === '') {
            $this->warn("Row {$rowNumber} skipped: missing key vehicle fields.");

            return false;
        }

        $modelYear = is_numeric($modelYearRaw) ? (int) $modelYearRaw : null;

        Vehicle::create([
            'plateNumber' => $plateNumber,
            'marque' => $marque,
            'chassis' => $chassis,
            'color' => $color !== '' ? $color : null,
            'modelYear' => $modelYear,
        ]);

        return true;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $headerCell) {
            $normalized = $this->normalizeHeader((string) $headerCell);

            if ($normalized === '' || isset($map[$normalized])) {
                continue;
            }

            $map[$normalized] = (int) $index;
        }

        return $map;
    }

    private function headerMatchesVehicleSchema(array $headerMap): bool
    {
        $hasPlate = $this->findHeaderIndex($headerMap, [
            'platenumber',
            'plateno',
            'immatriculation',
            'matricule',
            'nmatricule',
            'numeromatricule',
            'nomatricule',
        ]) !== null;

        $hasMarque = $this->findHeaderIndex($headerMap, [
            'marque',
            'marqueettype',
            'brand',
        ]) !== null;

        $hasChassis = $this->findHeaderIndex($headerMap, [
            'chassis',
            'numerochassis',
            'nochassis',
            'nchassis',
        ]) !== null;

        return $hasPlate || ($hasMarque && $hasChassis);
    }

    private function valueFromHeaders(array $row, array $headerMap, array $candidates): string
    {
        $index = $this->findHeaderIndex($headerMap, $candidates);

        if ($index === null) {
            return '';
        }

        return trim((string) ($row[$index] ?? ''));
    }

    private function findHeaderIndex(array $headerMap, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeHeader($candidate);

            if (isset($headerMap[$normalized])) {
                return (int) $headerMap[$normalized];
            }
        }

        return null;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));

        return preg_replace('/[^a-z0-9]+/', '', $header) ?? '';
    }

    private function getSharedStrings(ZipArchive $zip): array
    {
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedStringsXml === false) {
            return [];
        }

        $sharedStringsDoc = simplexml_load_string($sharedStringsXml);

        if (! $sharedStringsDoc instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];

        foreach ($sharedStringsDoc->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function getFirstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');

        if ($workbookXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookXml);

        if (! $workbook instanceof SimpleXMLElement || ! isset($workbook->sheets->sheet[0])) {
            return 'xl/worksheets/sheet1.xml';
        }

        $namespaces = $workbook->getNamespaces(true);
        $relationshipNamespace = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $firstSheet = $workbook->sheets->sheet[0];
        $relationshipId = (string) $firstSheet->attributes($relationshipNamespace)->id;

        if ($relationshipId === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($relationshipsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relationships = simplexml_load_string($relationshipsXml);

        if (! $relationships instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($relationships->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = (string) $relationship['Target'];

            if ($target === '') {
                return 'xl/worksheets/sheet1.xml';
            }

            return str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'xl/'.ltrim($target, '/');
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function extractXlsxRow(SimpleXMLElement $xmlRow, array $sharedStrings): array
    {
        $row = [];

        foreach ($xmlRow->c as $cell) {
            $cellReference = (string) $cell['r'];

            if ($cellReference === '') {
                continue;
            }

            preg_match('/[A-Z]+/', $cellReference, $matches);
            $columnLetters = $matches[0] ?? '';

            if ($columnLetters === '') {
                continue;
            }

            $columnIndex = $this->xlsxColumnIndex($columnLetters);

            if ($columnIndex < 0) {
                continue;
            }

            $type = (string) $cell['t'];

            if ($type === 's') {
                $sharedIndex = (int) ($cell->v ?? -1);
                $row[$columnIndex] = $sharedStrings[$sharedIndex] ?? '';
                continue;
            }

            if ($type === 'inlineStr') {
                $row[$columnIndex] = (string) ($cell->is->t ?? '');
                continue;
            }

            $row[$columnIndex] = (string) ($cell->v ?? '');
        }

        return $row;
    }

    private function xlsxColumnIndex(string $letters): int
    {
        $index = 0;
        $letters = strtoupper($letters);
        $length = strlen($letters);

        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
