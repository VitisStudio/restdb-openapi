# vitisstudio/restdb-openapi

Spec-driven Eloquent model generation from a plain **OpenAPI 3.0.3** document,
for the [RestDB Eloquent driver](https://github.com/VitisStudio/restdb). Companion to
`vitisstudio/restdb-jsonapi` — same `restdb:make-*-models` workflow, but for REST APIs
that describe themselves with OpenAPI rather than the JSON:API envelope.

```bash
php artisan restdb:make-openapi-models blog \
    --spec=openapi.json \
    --path=app/Models \
    --namespace="App\\Models"
```

Generated classes are **committed code you own** — one class per resource, with
`$connection`, `$table`, `$casts`, `belongsTo`/`hasMany` methods, and a
`@property` docblock. Re-running never overwrites an edited class without
`--force`.

```php
class Post extends Model
{
    use \Vitis\RestDB\OpenApi\IsOpenApiResource;

    protected $connection = 'blog';
    protected $table = 'posts';

    public function author(): BelongsTo   { return $this->belongsTo(Author::class, 'author_id'); }
    public function comments(): HasMany    { return $this->hasMany(Comment::class, 'post_id'); }
}
```

## What becomes a model

A `components.schemas` entry becomes a model **only when some path operation
reads or writes it** — i.e. it is the request/response body of a `get`/`post`/
`put`/`patch`/`delete`. The collection path's last static segment is the
`$table`. Schemas that only ever appear as nested value objects (an inline
`address`, a `PageMeta` envelope) never get a class — they stay attribute casts.

### Object names that collide across applications

Some APIs host the same object name under several applications —
`/objects/order-entry/document` and `/objects/purchasing/document` both end in
`document`. Taking the last segment alone would collapse them into one model,
and the last endpoint parsed would silently win.

When (and only when) a name is hosted under more than one application, the
`$table` is qualified as `application/object` and the class name folds the
application in. Names that are unique across the spec keep their bare segment,
so nothing changes for the common case:

| Collection path                      | `$table`                  | class                      |
| ------------------------------------ | ------------------------- | -------------------------- |
| `/objects/order-entry/document`      | `order-entry/document`    | `OrderEntryDocument`       |
| `/objects/purchasing/document`       | `purchasing/document`     | `PurchasingDocument`       |
| `/objects/order-entry/customer`      | `customer`                | `Customer`                 |

The parser unwraps the common envelopes to find the payload schema: `$ref`,
`allOf`/`oneOf`/`anyOf`, `type: array` + `items.$ref`, and one level of object
wrapper (`{ data: [ … ] }`, `{ results: [ … ] }`). List envelopes prefer the
array-valued member, so a `meta` sibling is never mistaken for the resource.

## Attributes and casts

Each scalar (or inline-object) property is an attribute. The Eloquent cast comes
from the OpenAPI `type` refined by `format`:

| OpenAPI `type` / `format`        | PHP type   | cast       |
| -------------------------------- | ---------- | ---------- |
| `integer` (incl. `int32/int64`)  | `int`      | `integer`  |
| `number` (incl. `float/double`)  | `float`    | `float`    |
| `boolean`                        | `bool`     | `boolean`  |
| `string:date` / `string:date-time` | `Carbon` | `datetime` |
| `object` / `array`               | `array`    | `array`    |
| everything else (incl. `byte`, `binary`, `password`) | `string` | — |

`id` is modelled as the key, not an attribute.

## Relationships — `$ref`-driven, spec-only

A property is a relationship **only when its schema is a `$ref` (or an array
whose `items` is a `$ref`) to another resource schema**:

- single `$ref` → `belongsTo`, foreign key `<name>_id`
- array of `$ref` → `hasMany`, foreign key `<singular table>_id`

Refs to non-resource schemas (value objects with no endpoint) and inline objects
stay attributes. No relations are guessed from property naming — if your API
signals references some other way (e.g. an inline `{ id, href }` object), add
those relation methods by hand in the committed model.

## Options

| Option               | Effect                                                                                                   |
| -------------------- | -------------------------------------------------------------------------------------------------------- |
| `--spec=`            | Path to the OpenAPI 3.0.3 JSON document (required)                                                        |
| `--path=`            | Directory to write classes into (default `app/Models`)                                                    |
| `--namespace=`       | Namespace for the generated classes (default `App\Models`)                                                |
| `--connection-trait` | Emit a `Has{Connection}Connection` trait holding `$connection` once, and have models `use` it             |
| `--endpoints=`       | Also emit an endpoint/field/model map class — see below                                                   |
| `--endpoints-path=`  | File to write the `--endpoints` class to (required with `--endpoints`)                                    |
| `--exclude=`         | Path substring(s) to ignore, repeatable — e.g. `--exclude=/uploadImage` drops RPC action endpoints         |
| `--force`            | Overwrite classes that already exist                                                                      |

## Endpoint, field, and model maps

Wiring a generated model set up by hand means restating, per resource, the
collection path the connection calls, the scalar field list a query names
explicitly, and the type-string → class mapping hydration needs. Maintained by
hand those drift from the models the moment a spec is regenerated, and the
failure is silent — a stale path 404s at runtime.

`--endpoints` emits all three as constants on one class, derived from the same
parse as the models, so they cannot fall out of step:

```bash
php artisan restdb:make-openapi-models erp \
    --spec=openapi.json \
    --path=app/Models --namespace="App\\Models" \
    --endpoints="App\\RestDB\\ErpEndpoints" \
    --endpoints-path=app/RestDB/ErpEndpoints.php
```

```php
final class ErpEndpoints
{
    public const MAP    = ['customer' => '/objects/order-entry/customer', …];
    public const FIELDS = ['customer' => ['id', 'name'], …];
    public const MODELS = ['customer' => \App\Models\Customer::class, …];
}
```

All three are keyed by the same `$table` the models carry (qualified where the
object name collides), so runtime lookups line up with the generated class:

- `MAP` → the connection's `endpoints` config (table → collection path)
- `FIELDS` → the connection's `query_fields` config (table → scalar fields a
  collection query must name explicitly; nested object/array members are omitted)
- `MODELS` → a morph map for hydration. When the API returns a related object
  identified only by its type string, this resolves the class to hydrate —
  register it with `Relation::enforceMorphMap(ErpEndpoints::MODELS)`.

## Name mapping

Property names map to Eloquent's snake_case via the connection's `name_mapping`
(`camel` default, or `kebab` / `none`), matching the JSON:API adapter.

## Scope

This package is **build-time model generation only** — it ships the parser, the
`restdb:make-openapi-models` command, and the `IsOpenApiResource` trait. It does
not register a runtime adapter; generated models run on whatever RestDB adapter
you configure for the connection.
