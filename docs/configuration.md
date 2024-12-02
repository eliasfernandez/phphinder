# Configuration

## The search engine

The search engine is the interface to add documents and indices to the storage, to fetch results from the storage.

### Adding document

```php
$engine->addDocument(['id' => 1, 'title' => 'Hi', 'text' => 'Hello world!']);
$engine->flush();
```

### Searching
```php
$engine->search('Hello OR Hi')
```


## Storage

Storage is an interface with the defined methods for the SearchEngine to interact with. Now, only the JsonStorage is defined but the plan is to add others like RedisStorage, DoctrineStorage, etc…

### JsonStorage usage

The `JsonStorage` make use of the FileSystem (native php file functions) to create the index and document files needed for the search. It is fast with a low number of items but not scalable when the index and document files become very big. 

```php
use PHPhinder\Index\JsonStorage;
use PHPhinder\SearchEngine;

$storage = new JsonStorage('var/phphinder');
$engine = new SearchEngine($storage);

```

These two lines will create the document and index files (if they don't exist yet) to work on the folder `var/phphinder` like this:
```
├── default_schema_docs.json
├── default_schema_text_index.json
└── default_schema_title_index.json
```

where `default_schema` refers to the schema name `DefaultSchema`(omitted in the Storage constructor but taken into account) ahd `*_docs.json` is the document file, and `*text_index.json` and `*title_index.json` refers to the reversed indices.

## Schema

The default Schema is only used for testing purposes but, if you start working with PHPhind you'll probably will want to design your own Schemas. Let's take a look to the DefaultSchema

```php
namespace PHPhinder\Schema;

class DefaultSchema implements Schema
{
    use SchemaTrait;

    public int $id = Schema::IS_STORED;
    public int $title = Schema::IS_REQUIRED | Schema::IS_STORED | Schema::IS_INDEXED;
    public int $text =  Schema::IS_INDEXED | Schema::IS_FULLTEXT;
}
```

A Schema only store information about the property names you want to store and the behaviour. In the DefaultSchema case: `id`, `title` and `text` are the properties of the documents we want to search and the behaviour is defined by one or more combination of Schema constant. Let's take a closer look to the constants:

### `Schema::IS_INDEXED`

Used if the search should look for this property values.  

### `Schema::IS_REQUIRED` 

If the property is required to add a document. 

### `Schema::IS_STORED` 

The property is stored on the documents index. Note that this is different from being searchable. For example, in the `DefaultSchema`, we don't expect results searchin by id but if there are results, the id will be shown.  

### `Schema::IS_FULLTEXT` 

If full text searches must work for this property.

## Tokenizer

The `Tokenizer` interface allow using different ways to split the terms in a sentence. By default is is using `RegexTokenizer` which will look for any non word (\W) matches to split the content.  

## Transformers

The transformers are tools to refine the tokens in the storage to avoid duplication. As its name indicates they transform a token in a different thing when storing but also before using them in the search. They all implement the interface `Transformer` 

### SymbolTransformer

Replace any non-alphabetic string (`/[^a-zA-Z0-9]+/`) from tokens. 

### LowerCaseTransformer

Lower case the token.

### StemmerTransformer

Stemming is a heuristic process of removing suffixes (and sometimes prefixes) from words to arrive (hopefully, most of the time) at the base word. The StemmerTransformer on the PHPhind package makes use of the library `wamania/php-stemmer`

## Filters

Similar to the transformer but directly filtering out not allowed tokens. They all implement the interface `Filter`  

### StopWordsFilter

Make use of a list of php lang files where there are defined the most common stop words in every language.

## Queries

The search use a very basic Query Language which allows search operations like the above:

```php
// Looks in all indices for this two word and return all the
// matches with the two
$engine->search('hello world');

// Looks in all indices for this two word and return all the
// matches with one of them
$engine->search('hello OR world');

// Looks in all indices for hello or documents with 'world'
// in the title and 'fun' in the keywords
$engine->search('hello OR (title:world keyword:fun)');

// Looks for matches of 'hello' in the title and
// 'world' on every field or the pattern foo* in the field
// `other`
$engine->search('title:hello (world OR other:foo*)');

// Looks for matches of 'hello' in the title and not
// 'world' on every field or the pattern foo* in the field
// `other`
$engine->search('title:hello NOT(world OR other:foo*)');
```


This makes the language very configurable and easy to use. Plans will make use the boost configurable as well. 
