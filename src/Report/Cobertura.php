<?php declare(strict_types=1);
/*
 * This file is part of phpunit/php-code-coverage.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\CodeCoverage\Report;

use function count;
use function dirname;
use function file_put_contents;
use function max;
use function range;
use function time;
use DOMImplementation;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Directory;
use SebastianBergmann\CodeCoverage\Driver\WriteOperationFailedException;
use SebastianBergmann\CodeCoverage\Node\File;

/**
 * @internal This class is not covered by the backward compatibility promise for phpunit/php-code-coverage
 */
final class Cobertura
{
    /**
     * @throws WriteOperationFailedException
     */
    public function process(CodeCoverage $coverage, ?string $target = null, ?string $name = null): string
    {
        $time = (string) time();

        $report = $coverage->getReport();

        $implementation = new DOMImplementation;

        $documentType = $implementation->createDocumentType(
            'coverage',
            '',
            'http://cobertura.sourceforge.net/xml/coverage-04.dtd'
        );

        $document               = $implementation->createDocument('', '', $documentType);
        $document->xmlVersion   = '1.0';
        $document->encoding     = 'UTF-8';
        $document->formatOutput = true;

        $coverageElement = $document->createElement('coverage');

        $linesValid   = $report->numberOfExecutedLines();
        $linesCovered = $report->numberOfExecutableLines();
        $lineRate     = $linesValid === 0 ? 0 : ($linesCovered / $linesValid);
        $coverageElement->setAttribute('line-rate', (string) $lineRate);

        $branchesValid   = $report->numberOfExecutedBranches();
        $branchesCovered = $report->numberOfExecutableBranches();
        $branchRate      = $branchesValid === 0 ? 0 : ($branchesCovered / $branchesValid);
        $coverageElement->setAttribute('branch-rate', (string) $branchRate);

        $coverageElement->setAttribute('lines-covered', (string) $report->numberOfExecutedLines());
        $coverageElement->setAttribute('lines-valid', (string) $report->numberOfExecutableLines());
        $coverageElement->setAttribute('branches-covered', (string) $report->numberOfExecutedBranches());
        $coverageElement->setAttribute('branches-valid', (string) $report->numberOfExecutableBranches());
        $coverageElement->setAttribute('complexity', '');
        $coverageElement->setAttribute('version', '0.4');
        $coverageElement->setAttribute('timestamp', $time);

        $document->appendChild($coverageElement);

        $sourcesElement = $document->createElement('sources');
        $coverageElement->appendChild($sourcesElement);

        $sourceElement = $document->createElement('source', $report->pathAsString());
        $sourcesElement->appendChild($sourceElement);

        $packagesElement = $document->createElement('packages');
        $coverageElement->appendChild($packagesElement);

        $complexity = 0;

        foreach ($report as $item) {
            if (!$item instanceof File) {
                continue;
            }

            $packageElement    = $document->createElement('package');
            $packageComplexity = 0;
            $packageName       = $name ?? '';

            $packageElement->setAttribute('name', $packageName);

            $linesValid   = $item->numberOfExecutableLines();
            $linesCovered = $item->numberOfExecutedLines();
            $lineRate     = $linesValid === 0 ? 0 : ($linesCovered / $linesValid);

            $packageElement->setAttribute('line-rate', (string) $lineRate);

            $branchesValid   = $item->numberOfExecutableBranches();
            $branchesCovered = $item->numberOfExecutedBranches();
            $branchRate      = $branchesValid === 0 ? 0 : ($branchesCovered / $branchesValid);

            $packageElement->setAttribute('branch-rate', (string) $branchRate);

            $packageElement->setAttribute('complexity', '');
            $packagesElement->appendChild($packageElement);

            $classesElement = $document->createElement('classes');

            $packageElement->appendChild($classesElement);

            $classes      = $item->classesAndTraits();
            $coverageData = $item->lineCoverageData();

            foreach ($classes as $className => $class) {
                $complexity += $class['ccn'];
                $packageComplexity += $class['ccn'];

                if (!empty($class['package']['namespace'])) {
                    $className = $class['package']['namespace'] . '\\' . $className;
                }

                $linesValid   = $class['executableLines'];
                $linesCovered = $class['executedLines'];
                $lineRate     = $linesValid === 0 ? 0 : ($linesCovered / $linesValid);

                $branchesValid   = $class['executableBranches'];
                $branchesCovered = $class['executedBranches'];
                $branchRate      = $branchesValid === 0 ? 0 : ($branchesCovered / $branchesValid);

                $classElement = $document->createElement('class');

                $classElement->setAttribute('name', $className);
                $classElement->setAttribute('filename', str_replace($report->pathAsString() . '/', '', $item->pathAsString()));
                $classElement->setAttribute('line-rate', (string) $lineRate);
                $classElement->setAttribute('branch-rate', (string) $branchRate);
                $classElement->setAttribute('complexity', (string) $class['ccn']);

                $classesElement->appendChild($classElement);

                $methodsElement = $document->createElement('methods');

                $classElement->appendChild($methodsElement);

                $classLinesElement = $document->createElement('lines');

                $classElement->appendChild($classLinesElement);

                foreach ($class['methods'] as $methodName => $method) {
                    if ($method['executableLines'] === 0) {
                        continue;
                    }

                    $methodCount = 0;

                    foreach (range($method['startLine'], $method['endLine']) as $line) {
                        if (isset($coverageData[$line]) && $coverageData[$line] !== null) {
                            $methodCount = max($methodCount, count($coverageData[$line]));

                            $classLineElement = $document->createElement('line');
                            $classLineElement->setAttribute('number', (string) $line);
                            $classLineElement->setAttribute('hits', (string) count($coverageData[$line]));

                            $classLinesElement->appendChild($classLineElement);
                        }
                    }

                    $linesValid   = $method['executableLines'];
                    $linesCovered = $method['executedLines'];
                    $lineRate     = $linesValid === 0 ? 0 : ($linesCovered / $linesValid);

                    $branchesValid   = $method['executableBranches'];
                    $branchesCovered = $method['executedBranches'];
                    $branchRate      = $branchesValid === 0 ? 0 : ($branchesCovered / $branchesValid);

                    $methodElement = $document->createElement('method');

                    $methodElement->setAttribute('name', $methodName);
                    $methodElement->setAttribute('signature', $method['signature']);
                    $methodElement->setAttribute('line-rate', (string) $lineRate);
                    $methodElement->setAttribute('branch-rate', (string) $branchRate);
                    $methodElement->setAttribute('complexity', (string) $method['ccn']);

                    $methodLinesElement = $document->createElement('lines');

                    $methodElement->appendChild($methodLinesElement);

                    $methodLineElement = $document->createElement('line');

                    $methodLineElement->setAttribute('number', (string) $method['startLine']);
                    $methodLineElement->setAttribute('hits', (string) $methodCount);

                    $methodLinesElement->appendChild($methodLineElement);
                    $methodsElement->appendChild($methodElement);
                }
            }

            $packageElement->setAttribute('complexity', (string) $packageComplexity);
        }

        $coverageElement->setAttribute('complexity', (string) $complexity);

        $buffer = $document->saveXML();

        if ($target !== null) {
            Directory::create(dirname($target));

            if (@file_put_contents($target, $buffer) === false) {
                throw new WriteOperationFailedException($target);
            }
        }

        return $buffer;
    }
}
