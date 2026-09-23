<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Structural validation of the descriptor files a package ships.
 *
 * This exists because of a real defect. Two access operation codes were once
 * appended after the `presets` key of an `access.yaml`; YAML read them as two
 * maps inside the presets list, the operations were never declared, and the
 * lint, analysis, test, scope, metadata and evidence gates of three
 * repositories all stayed green. Nothing was checking the shape of the file.
 * Now something does, and it runs in the same report as operation coverage.
 */
final class PackageDescriptorFileValidator
{
    /**
     * Per file name: the closed top-level key set, and for each list key the
     * kind its members must have.
     */
    private const CONTRACTS = [
        'access.yaml' => [
            'schema' => 'larena.access.descriptor.v1',
            'keys' => ['schema', 'package', 'version', 'operations', 'presets', 'nonclaims'],
            'lists' => ['operations' => 'map_with_code', 'presets' => 'scalar'],
        ],
        'audit.yaml' => [
            'schema' => 'larena.audit.package-events.v1',
            'keys' => [
                'schema', 'package', 'version', 'storage_owner', 'retention', 'atomic_completion',
                'events', 'allowed_payload_fields', 'forbidden_payload_fields', 'nonclaims',
            ],
            'lists' => ['events' => 'map_with_type'],
        ],
    ];

    /**
     * Findings that describe drift rather than a defect. A file that predates
     * the descriptor schema is worth naming, but it is not something this
     * package can fix, and failing on it would mean the coverage gate could
     * never go green for a reason nobody in the current batch may touch.
     */
    private const NOTICE_REASON_CODES = ['schema_absent'];

    /**
     * @return list<array{file: string, reason_code: string, detail: string}>
     */
    public function violations(string $packagePath): array
    {
        $violations = [];

        foreach (self::CONTRACTS as $fileName => $contract) {
            $path = rtrim($packagePath, '/') . '/' . $fileName;
            if (!is_file($path)) {
                continue;
            }

            foreach ($this->violationsForFile($path, $fileName, $contract) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param array{schema: string, keys: list<string>, lists: array<string, string>} $contract
     * @return list<array{file: string, reason_code: string, detail: string}>
     */
    private function violationsForFile(string $path, string $fileName, array $contract): array
    {
        try {
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (Throwable $exception) {
            return [['file' => $path, 'reason_code' => 'invalid_yaml', 'detail' => $exception->getMessage()]];
        }

        if (!is_array($parsed) || array_is_list($parsed)) {
            return [['file' => $path, 'reason_code' => 'not_a_mapping', 'detail' => 'the document must be a mapping']];
        }

        $violations = [];

        $declaredSchema = $parsed['schema'] ?? null;
        if ($declaredSchema === null) {
            // An unversioned descriptor: older than the schema, and still read
            // by the packages that ship it. Named, not failed.
            $violations[] = [
                'file' => $path,
                'reason_code' => 'schema_absent',
                'detail' => 'the file declares no schema, so only its list shapes are checked',
            ];
        } elseif ($declaredSchema !== $contract['schema']) {
            $violations[] = [
                'file' => $path,
                'reason_code' => 'unknown_schema',
                'detail' => 'expected ' . $contract['schema'],
            ];
        }

        foreach (array_keys($parsed) as $key) {
            if (!is_string($key) || !in_array($key, $contract['keys'], true)) {
                $violations[] = [
                    'file' => $path,
                    'reason_code' => 'unknown_top_level_key',
                    'detail' => (string) $key,
                ];
            }
        }

        foreach ($contract['lists'] as $listKey => $memberKind) {
            $list = $parsed[$listKey] ?? null;
            if ($list === null) {
                continue;
            }

            if (!is_array($list) || !array_is_list($list)) {
                $violations[] = [
                    'file' => $path,
                    'reason_code' => 'not_a_list',
                    'detail' => $listKey . ' must be a list',
                ];

                continue;
            }

            foreach ($list as $index => $member) {
                $detail = $listKey . '[' . $index . ']';
                $isMap = is_array($member) && !array_is_list($member);

                if ($memberKind === 'scalar' && $isMap) {
                    $violations[] = [
                        'file' => $path,
                        'reason_code' => 'unexpected_map_in_list',
                        'detail' => $detail . ' is a mapping in a list of names: an entry was appended under the wrong key',
                    ];

                    continue;
                }

                if ($memberKind === 'scalar') {
                    continue;
                }

                if (!$isMap) {
                    $violations[] = [
                        'file' => $path,
                        'reason_code' => 'unexpected_scalar_in_list',
                        'detail' => $detail . ' must be a mapping',
                    ];

                    continue;
                }

                $requiredKey = $memberKind === 'map_with_code' ? 'code' : 'type';
                if (!array_key_exists($requiredKey, $member)) {
                    $violations[] = [
                        'file' => $path,
                        'reason_code' => 'missing_member_key',
                        'detail' => $detail . ' must carry a "' . $requiredKey . '"',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Whether a finding describes a defect that someone can fix today.
     */
    public static function isFailure(string $reasonCode): bool
    {
        return !in_array($reasonCode, self::NOTICE_REASON_CODES, true);
    }

    /**
     * The access operation codes a package declares, or an empty list when the
     * file is absent or malformed. Callers pair this with `violations()`: a
     * malformed file yields no codes *and* a violation, never a silent zero.
     *
     * @return list<string>
     */
    public function declaredAccessOperationCodes(string $packagePath): array
    {
        $path = rtrim($packagePath, '/') . '/access.yaml';
        if (!is_file($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($parsed)) {
            return [];
        }

        $operations = $parsed['operations'] ?? null;
        if (!is_array($operations) || !array_is_list($operations)) {
            return [];
        }

        $codes = [];
        foreach ($operations as $operation) {
            if (is_array($operation) && isset($operation['code']) && is_string($operation['code'])) {
                $codes[] = $operation['code'];
            }
        }

        sort($codes);

        return $codes;
    }
}
