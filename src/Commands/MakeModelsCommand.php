<?php

declare(strict_types=1);

namespace Vitis\RestDB\OpenApi\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Vitis\RestDB\OpenApi\OpenApiSpecParser;
use Vitis\RestDB\OpenApi\Support\NameMapper;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * OpenAPI 3.0.3 document -> physical Eloquent model classes. Generated classes
 * are committed code the user owns: one class per resource schema exposed at a
 * path, with the trait, $connection, $table, $casts from schema types/formats,
 * belongsTo/hasMany methods from $ref-derived relationships, and a @property
 * docblock. Re-running never overwrites an edited class without --force.
 */
final class MakeModelsCommand extends Command
{
    protected $signature = 'restdb:make-openapi-models
        {connection : The restdb connection name}
        {--spec= : Path to the API spec file (OpenAPI 3.0.3 JSON)}
        {--path=app/Models : Directory to write classes into}
        {--namespace=App\\Models : Namespace for the generated classes}
        {--connection-trait : Emit a Has{Connection}Connection trait holding $connection once, and have models use it instead of an inline property}
        {--endpoints= : Also emit a resource endpoint+field map class (FQCN, e.g. App\\RestDB\\IntacctEndpoints) derived from the same spec as the models}
        {--endpoints-path= : File path to write the --endpoints class to (required with --endpoints)}
        {--exclude=* : Path substring(s) to ignore, e.g. --exclude=/uploadImage (drops RPC action endpoints)}
        {--force : Overwrite classes that already exist}';

    protected $description = 'Generate physical Eloquent model classes from an OpenAPI 3.0.3 spec';

    public function handle(Repository $config): int
    {
        $connection = $this->argument('connection');
        $connection = is_string($connection) ? $connection : '';
        $spec = $this->option('spec');

        if (! is_string($spec) || $spec === '') {
            $this->error('Provide the spec file: --spec=path/to/openapi.json');

            return self::FAILURE;
        }

        $exclude = $this->option('exclude');
        $exclude = is_array($exclude) ? array_values(array_filter($exclude, 'is_string')) : [];

        $manifest = (new OpenApiSpecParser)->excludePaths($exclude)->parse($spec);
        $resources = ConnectionConfig::stringKeyed($manifest['resources'] ?? null);

        if ($resources === []) {
            $this->error('The spec exposes no resource schemas (no request/response bodies at any path).');

            return self::FAILURE;
        }

        $style = $config->get("database.connections.{$connection}.name_mapping", 'camel');
        $names = new NameMapper(is_string($style) ? $style : 'camel');

        $path = $this->option('path');
        $path = rtrim(is_string($path) ? $path : 'app/Models', '/');
        $namespace = $this->option('namespace');
        $namespace = trim(is_string($namespace) ? $namespace : 'App\Models', '\\');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // When --connection-trait is set the $connection lives in one shared
        // trait under {namespace}\Concerns; every generated model uses it and
        // omits the inline property. Trait is written once, never clobbering an
        // edited copy without --force.
        $connectionTrait = $this->option('connection-trait') === true
            ? $this->writeConnectionTrait($path, $namespace, $connection)
            : null;

        $classByType = [];
        $invalidTypes = [];

        foreach (array_keys($resources) as $type) {
            // A qualified type ('order-entry/document') folds its application
            // into the class name (OrderEntryDocument); '/' is not a legal
            // identifier character, so map it to a word separator first.
            $class = Str::studly(Str::singular(str_replace('/', '-', $names->toModel((string) $type))));

            // Path templates and RPC actions can yield names that are not legal
            // PHP class identifiers (e.g. 'Document::{documentName}') or are
            // reserved words ('List', 'Print', 'Class'). Skip them — a relation
            // pointing at a skipped type is simply omitted, to be added by hand.
            if (! $this->isValidClassName($class)) {
                $invalidTypes[(string) $type] = $class;

                continue;
            }

            $classByType[$type] = $class;
        }

        $written = 0;
        $skipped = 0;

        foreach ($invalidTypes as $type => $class) {
            $this->line("Skipped {$type} — '{$class}' is not a valid PHP class name (reserved word or illegal characters).");
            $skipped++;
        }

        foreach ($resources as $type => $resource) {
            if (! isset($classByType[$type])) {
                continue; // invalid class name — already reported above
            }

            $class = $classByType[$type];
            $file = "{$path}/{$class}.php";

            if (is_file($file) && $this->option('force') !== true) {
                $this->line("Skipped {$class} — {$file} exists (use --force to overwrite).");
                $skipped++;

                continue;
            }

            file_put_contents($file, $this->render(
                $namespace,
                $class,
                $connection,
                (string) $type,
                ConnectionConfig::stringKeyed($resource),
                $classByType,
                $names,
                basename($spec),
                $connectionTrait,
            ));

            $written++;
        }

        $this->info("Generated {$written} model(s) in [{$path}]".($skipped > 0 ? ", skipped {$skipped} existing" : '').'.');

        $endpoints = $this->option('endpoints');

        if (is_string($endpoints) && $endpoints !== '' && ! $this->writeEndpoints($endpoints, $resources, $classByType, $namespace, $names, basename($spec))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Emit a single class holding the resource endpoint map (table => collection
     * path) and query field map (table => scalar field list) for every resource
     * that became a model. Both are derived from the same manifest as the
     * models, so the runtime lookups can never drift from the generated classes.
     * Wire them into the connection's 'endpoints' and 'query_fields' config.
     *
     * @param  array<string, mixed>  $resources  manifest resources keyed by type
     * @param  array<string, string>  $classByType
     */
    private function writeEndpoints(string $fqcn, array $resources, array $classByType, string $modelNamespace, NameMapper $names, string $specName): bool
    {
        $file = $this->option('endpoints-path');

        if (! is_string($file) || $file === '') {
            $this->error('Provide --endpoints-path=path/to/Class.php when using --endpoints.');

            return false;
        }

        $fqcn = trim($fqcn, '\\');
        $separator = strrpos($fqcn, '\\');
        $namespace = $separator === false ? '' : substr($fqcn, 0, $separator);
        $class = $separator === false ? $fqcn : substr($fqcn, $separator + 1);

        $map = [];
        $fields = [];
        $models = [];

        foreach ($classByType as $type => $modelClass) {
            $resource = ConnectionConfig::stringKeyed($resources[$type] ?? null);
            $table = $names->toModel((string) $type);

            if (is_string($resource['path'] ?? null) && $resource['path'] !== '') {
                $map[$table] = $resource['path'];
            }

            $fieldList = is_array($resource['fields'] ?? null)
                ? array_values(array_filter($resource['fields'], is_string(...)))
                : [];

            if ($fieldList !== []) {
                $fields[$table] = $fieldList;
            }

            $models[$table] = '\\'.$modelNamespace.'\\'.$modelClass;
        }

        ksort($map);
        ksort($fields);
        ksort($models);

        $mapBody = '';

        foreach ($map as $table => $collectionPath) {
            $mapBody .= "        '".$this->escapeSingleQuoted($table)."' => '".$this->escapeSingleQuoted($collectionPath)."',\n";
        }

        $fieldsBody = '';

        foreach ($fields as $table => $list) {
            $items = implode(', ', array_map(fn (string $field): string => "'".$this->escapeSingleQuoted($field)."'", $list));
            $fieldsBody .= "        '".$this->escapeSingleQuoted($table)."' => [{$items}],\n";
        }

        $modelsBody = '';

        foreach ($models as $table => $modelFqcn) {
            $modelsBody .= "        '".$this->escapeSingleQuoted($table)."' => {$modelFqcn}::class,\n";
        }

        $namespaceBlock = $namespace === '' ? '' : "\nnamespace {$namespace};\n";

        $contents = <<<PHP
        <?php

        declare(strict_types=1);
        {$namespaceBlock}
        /**
         * Generated by restdb:make-openapi-models from {$specName}, keyed by resource
         * table so the runtime lookups never drift from the models:
         *   MAP    — table => collection path      (connection 'endpoints')
         *   FIELDS — table => scalar query fields   (connection 'query_fields')
         *   MODELS — object-type string => model    (a morph map for hydration:
         *            when the API returns a related object identified only by its
         *            type string, this resolves the class to hydrate — register
         *            with Relation::enforceMorphMap({$class}::MODELS)).
         */
        final class {$class}
        {
            /** @var array<string, string> */
            public const MAP = [
        {$mapBody}    ];

            /** @var array<string, list<string>> */
            public const FIELDS = [
        {$fieldsBody}    ];

            /** @var array<string, class-string> */
            public const MODELS = [
        {$modelsBody}    ];
        }

        PHP;

        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($file, $contents);

        $this->info("Wrote endpoint map [{$fqcn}] to [{$file}] (".count($map).' resources).');

        return true;
    }

    /** Escape a value for a single-quoted PHP string literal. */
    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * A string is a usable model class name only if it is a syntactically legal
     * PHP identifier and not a reserved word — path templates ('{documentName}')
     * and RPC verbs ('List', 'Print', 'Class') fail one or both.
     */
    private function isValidClassName(string $class): bool
    {
        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $class) !== 1) {
            return false;
        }

        /** @var list<string> $reserved */
        static $reserved = [
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
            'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
            'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
            'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final',
            'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
            'implements', 'include', 'include_once', 'instanceof', 'insteadof',
            'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
            'private', 'protected', 'public', 'readonly', 'require', 'require_once',
            'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
            'var', 'while', 'xor', 'yield',
            // Reserved for language use as type / class names.
            'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null',
            'object', 'parent', 'self', 'string', 'true', 'void',
        ];

        return ! in_array(strtolower($class), $reserved, true);
    }

    /**
     * Write the shared connection trait once into {namespace}\Concerns and
     * return its short name + FQCN for composition into each model. Like the
     * models, an existing (possibly edited) trait is never overwritten without
     * --force.
     *
     * @return array{name: string, fqcn: string}
     */
    private function writeConnectionTrait(string $path, string $namespace, string $connection): array
    {
        $name = 'Has'.Str::studly($connection).'Connection';
        $traitNamespace = $namespace.'\\Concerns';
        $fqcn = $traitNamespace.'\\'.$name;
        $dir = $path.'/Concerns';
        $file = "{$dir}/{$name}.php";

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (is_file($file) && $this->option('force') !== true) {
            return ['name' => $name, 'fqcn' => $fqcn];
        }

        // The connection is set in the trait's initializer, not as a property:
        // Eloquent's Model already declares $connection, and a trait property
        // with a different default is an incompatible composition (fatal). The
        // initialize{Trait}() hook Eloquent calls on boot has no such conflict.
        file_put_contents($file, <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$traitNamespace};

        /**
         * Carries the restdb connection name for every generated {$connection}
         * model, so the connection is configured in one place rather than
         * repeated on each class.
         */
        trait {$name}
        {
            public function initialize{$name}(): void
            {
                \$this->setConnection('{$connection}');
            }
        }

        PHP);

        return ['name' => $name, 'fqcn' => $fqcn];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $classByType
     * @param  array{name: string, fqcn: string}|null  $connectionTrait  shared trait carrying $connection, or null for the inline property
     */
    private function render(string $namespace, string $class, string $connection, string $type, array $resource, array $classByType, NameMapper $names, string $specName, ?array $connectionTrait = null): string
    {
        $table = is_string($resource['table'] ?? null) ? $resource['table'] : $type;
        $table = $names->toModel($table);
        $attributes = ConnectionConfig::stringKeyed($resource['attributes'] ?? null);
        $relationships = ConnectionConfig::stringKeyed($resource['relationships'] ?? null);

        $properties = [' * @property string|null $id'];
        $casts = [];

        foreach ($attributes as $name => $schemaType) {
            $column = $names->toModel($name);
            [$phpType, $cast] = $this->mapType(is_string($schemaType) ? $schemaType : 'string');
            $properties[] = " * @property {$phpType}|null \${$column}";

            if ($cast !== null) {
                $casts[] = "        '{$column}' => '{$cast}',";
            }
        }

        $uses = [
            'use Illuminate\Database\Eloquent\Model;',
            'use Vitis\RestDB\OpenApi\IsOpenApiResource;',
        ];

        if ($connectionTrait !== null) {
            $uses[] = "use {$connectionTrait['fqcn']};";
        }
        $methods = [];
        $seenMethods = [];

        foreach ($relationships as $name => $relationship) {
            $relationship = ConnectionConfig::stringKeyed($relationship);
            $targetType = is_string($relationship['type'] ?? null) ? $relationship['type'] : null;
            $kind = $relationship['kind'] ?? 'to-one';

            if ($targetType === null || ! isset($classByType[$targetType])) {
                continue; // target resource is not in this spec — add the relation by hand
            }

            $related = $classByType[$targetType];
            $method = Str::camel($names->toModel($name));

            // PHP method names are case-insensitive, so 'subTotals' and
            // 'subtotals' would be a fatal redeclare. Keep the first, skip the
            // rest — the duplicate can be added by hand under a distinct name.
            $methodKey = strtolower($method);

            if ($method === '' || isset($seenMethods[$methodKey])) {
                continue;
            }

            $seenMethods[$methodKey] = true;

            if ($kind === 'to-many') {
                $uses[] = 'use Illuminate\Database\Eloquent\Relations\HasMany;';
                $foreignKey = Str::snake(Str::singular($table)).'_id';
                $methods[] = <<<PHP

    public function {$method}(): HasMany
    {
        return \$this->hasMany({$related}::class, '{$foreignKey}');
    }
PHP;
            } else {
                $uses[] = 'use Illuminate\Database\Eloquent\Relations\BelongsTo;';
                $foreignKey = $names->toModel($name).'_id';
                $methods[] = <<<PHP

    public function {$method}(): BelongsTo
    {
        return \$this->belongsTo({$related}::class, '{$foreignKey}');
    }
PHP;
            }
        }

        $uses = array_unique($uses);
        sort($uses);

        $castsBlock = $casts === [] ? '' : "\n    protected \$casts = [\n".implode("\n", $casts)."\n    ];\n";
        $usesBlock = implode("\n", $uses);
        $propertiesBlock = implode("\n", $properties);
        $methodsBlock = implode("\n", $methods);

        // The connection is either carried by the shared trait (composed into the
        // `use` statement below) or declared inline on the class. Traits are
        // sorted so the emitted order matches Pint's ordered_traits — a clean
        // re-run leaves no diff.
        $traitNames = ['IsOpenApiResource'];

        if ($connectionTrait !== null) {
            $traitNames[] = $connectionTrait['name'];
        }

        sort($traitNames);
        $traitUse = implode(', ', $traitNames);
        $connectionProperty = $connectionTrait !== null ? '' : "\n    protected \$connection = '{$connection}';\n";

        // Exactly one newline before the closing brace, whether or not casts /
        // relations were emitted, so the generated file is pint-clean.
        $body = rtrim("    public \$timestamps = false;\n".$castsBlock.$methodsBlock, "\n");

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$usesBlock}

/**
 * Generated by restdb:make-openapi-models from {$specName}. This is committed
 * code — edit freely; re-running the command will not overwrite it without
 * --force.
 *
{$propertiesBlock}
 */
class {$class} extends Model
{
    use {$traitUse};
{$connectionProperty}
    protected \$table = '{$table}';

    protected \$guarded = [];

{$body}
}

PHP;
    }

    /**
     * OpenAPI 3.0.3 type[:format] -> [php type, Eloquent cast]. Formats refine
     * the base type: integers stay integer regardless of int32/int64; dates
     * cast to Carbon; byte/binary/password stay plain strings.
     *
     * Nested object/array members get NO cast: the driver's JSON parser hands
     * back already-decoded PHP arrays, whereas Eloquent's 'array' cast expects a
     * JSON string in the column and would json_decode() an array. The docblock
     * still types them as array — the value simply arrives ready to use.
     *
     * @return array{string, string|null} php type + cast
     */
    private function mapType(string $schemaType): array
    {
        return match ($schemaType) {
            'integer', 'integer:int32', 'integer:int64' => ['int', 'integer'],
            'number', 'number:float', 'number:double' => ['float', 'float'],
            'boolean' => ['bool', 'boolean'],
            'string:date-time', 'string:date' => ['\Illuminate\Support\Carbon', 'datetime'],
            'array', 'object' => ['array', null],
            default => ['string', null],
        };
    }
}
