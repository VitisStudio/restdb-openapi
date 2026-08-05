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

## Name mapping

Property names map to Eloquent's snake_case via the connection's `name_mapping`
(`camel` default, or `kebab` / `none`), matching the JSON:API adapter.

## Scope

This package is **build-time model generation only** — it ships the parser, the
`restdb:make-openapi-models` command, and the `IsOpenApiResource` trait. It does
not register a runtime adapter; generated models run on whatever RestDB adapter
you configure for the connection.
