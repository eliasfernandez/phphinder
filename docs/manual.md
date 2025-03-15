# The Search Engine Manual

The search engine provides an interface to add documents and indices to storage, and to retrieve search results.

### Adding Documents

```php
$engine->addDocument(['_id' => 1, 'title' => 'Hi', 'text' => 'Hello world!']);
$engine->flush();
```

### Searching

```php
$engine->search('Hello OR Hi');
```

## Storage

Storage is an interface with defined methods that allow the `SearchEngine` to interact with stored data. Currently, `JsonStorage`, `DbalStorage` and `RedisStorage` are implemented. Future plans include other options.

### JsonStorage Usage

`JsonStorage` uses PHP's native file functions to create index and document files. It's fast for small datasets but less suitable for large-scale scenarios where files become too big.

```php
use PHPhinder\Index\JsonStorage;
use PHPhinder\SearchEngine;

$storage = new JsonStorage('var/phphinder');
$engine = new SearchEngine($storage);
```

This setup creates the necessary document and index files in the `var/phphinder` folder:

```
├── default_schema_docs.json
├── default_schema_text_index.json
└── default_schema_title_index.json
```

- `default_schema` corresponds to the schema name (`DefaultSchema` is the default).
- `*_docs.json` stores documents.
- `*_text_index.json` and `*_title_index.json` are reversed indices for respective fields.

**Important**: Storing and retrieving results from the file system is fast with a few results but doesn't scale well with bigger data structure. Plan carefully the index creation and the stored data if you want to use it. If the data volume to search is very big, consider DbalStorage instead. 

### DbalStorage Usage

`DbalStorage` uses the[ Doctrine DBAL](https://github.com/doctrine/dbal) package to store the data. Its nature is to be database agnostic which means that you can use any of the databases engines it supports: MySQL, MariaDB, PostgreSQL, Oracle, MS Sql Server, SQLite ...

```php
use PHPhinder\Index\DbalStorage;
use PHPhinder\SearchEngine;

$storage = new DbalStorage('pdo-sqlite:///var/search.db');
$engine = new SearchEngine($storage);
```

This setup creates the necessary document and index tables in an existing database:

```sql
    CREATE TABLE default_schema_docs (
        id character varying(255) NOT NULL,
        _id text NOT NULL,
        title text NOT NULL,
        PRIMARY KEY (id)
    );
    CREATE TABLE default_schema_title (
        k character varying(255) NOT NULL,
        ids text NOT NULL,
        PRIMARY KEY (k)
    );
    CREATE TABLE default_schema_text (
        k character varying(255) NOT NULL,
        ids text NOT NULL,
        PRIMARY KEY (k)
    );
```

### RedisStorage Usage

`RedisStorage` uses the[Predis](https://github.com/predis/predis) package to store the data. 

```php
use PHPhinder\Index\RedisStorage;
use PHPhinder\SearchEngine;

$storage = new RedisStorage('tcp://127.0.0.1:6379');
$engine = new SearchEngine($storage);
```

This setup creates the hash (prefixed with `phphinder:`), keys and values on the Redis engine. By instance, for a Schema called TestSchema: 

```
phphinder
└── test_schema:
    ├── _id
    ├── docs
    ├── states
    ├── text
    └── title
```


## Schema

A schema defines the structure and behavior of the properties in documents. While `DefaultSchema` is provided for testing, you’ll likely want to create your own schema for custom use cases.

Example of `DefaultSchema`:

```php
namespace PHPhinder\Schema;

class DefaultSchema implements Schema
{
    use SchemaTrait;

    public int $_id = Schema::IS_STORED;
    public int $title = Schema::IS_REQUIRED | Schema::IS_STORED | Schema::IS_INDEXED;
    public int $text = Schema::IS_INDEXED | Schema::IS_FULLTEXT;
}
```

A schema specifies:
- **Properties** (`_id`, `title`, `text` in the example).
- **Behavior** through constants like `Schema::IS_INDEXED`.

### Schema Constants:

- **`Schema::IS_INDEXED`**: Makes a property searchable.
- **`Schema::IS_REQUIRED`**: Ensures the property is present when adding documents.
- **`Schema::IS_STORED`**: Stores the property in documents for retrieval, even if not searchable.
- **`Schema::IS_FULLTEXT`**: Enables full-text search for the property.
- **`Schema::IS_UNIQUE`**: Exists only one of these properties for document. Any new document added with the same value on this propery will override the previous one.

These constants can be combined using the bitwise OR operator (|) to define multiple behaviors for a single property.

## Tokenizer

Tokenizers split sentences into terms. The default tokenizer is `RegexTokenizer`, which splits content on non-word (`\W`) characters.

## Transformers

Transformers refine tokens before storing or searching. All transformers implement the `Transformer` interface.

### Built-in Transformers:

- **`SymbolTransformer`**: Removes non-alphanumeric characters (`/[^a-zA-Z0-9]+/`).
- **`LowerCaseTransformer`**: Converts tokens to lowercase.
- **`StemmerTransformer`**: Reduces words to their base form using `wamania/php-stemmer`.

## Filters

Filters exclude unwanted tokens. All filters implement the `Filter` interface.

### Built-in Filters:

- **`StopWordsFilter`**: Filters out common stop words using predefined language files.

## Queries

The search engine supports a simple query language. Examples:

```php
// Finds documents containing both "hello" and "world".
$engine->search('hello world');

// Finds documents containing "hello" or "world".
$engine->search('hello OR world');

// Combines searches: "hello" OR ("world" in title AND "fun" in keywords).
$engine->search('hello OR (title:world keyword:fun)');

// Searches for "hello" in title AND ("world" or "foo*" in "other").
$engine->search('title:hello (world OR other:foo*)');

// Excludes "world": Searches for "hello" in title but NOT ("world" or "foo*" in "other").
$engine->search('title:hello NOT(world OR other:foo*)');

// Finds documents containing both "hello world" in this exact order.
$engine->search('title:"hello world")');
```

This query language is powerful, flexible, and easy to use. Future updates may include configurable boost levels for terms.

## Fuzzy searches

In case there is no direct result for a word, the engine will try to find similar matches. It uses the Levenshtein distance to calculate the similarity and the state of every term in non unique indices is stored using the amazing library [State-set-index](https://github.com/Toflar/state-set-index).

Matches will be shown only for words greater than 5 characters and distance of 1 or greater than 9 and distance of 2.


