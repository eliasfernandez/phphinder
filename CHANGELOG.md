# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.1.5-beta] - 2025-01-10
### Added
- Add Redis Support, using the popular library `predis/predis`, adds support to use Redis as the search Storage.
  * `RedisIndex`
  * `RedisStorage`
- Add this CHANGELOG

## [0.1.4-beta] - 2025-01-10
### Changed
- Improve performance state index updates by using incremental updates
- Some cleaning and refactor assisted by phpstan and phpcs

## [0.1.3-beta] - 2025-01-08
### Added
Typo tolerance searches

### Fixed
Issue with string containing "0"



## [0.1.0-beta] - 2025-01-01
### Added

- DbalStorage, Split/Join files logic on Json Storage.
    * Added new logic for Database Storage using Doctrine Dbal
    * Abstraction of the Storage class
    * Improve speed on file operation with the split/join methods

- Update docs with `Schema::IS_UNIQUE` properties
    * Adds necessary logic to update unique documents.
    * Add `isEmpty` method to `FileIndex`
 
### Changed
- Optimized file handling for large index files.
- Improved integration with Symfony components for seamless installation.

### Fixed
- Corrected index alignment during document updates to ensure data consistency.
- Fixing compatibility with symfony doctrine
- Fix stop words loading from the wrong place

---

## [0.0.1-alpha] - 2024-12-04

### Added
- Basic search engine implementation with PHP-based indexing.
- Introduced `JsonStorage` as a default storage backend.
- Added support for full-text search with `Schema::IS_FULLTEXT`.
- Initial set of `Schema` constants: `IS_INDEXED`, `IS_REQUIRED`, `IS_STORED`, `IS_FULLTEXT`.
- Tokenizer and Transformer system with default implementations:
  - `RegexTokenizer`
  - `LowerCaseTransformer`
  - `SymbolTransformer`
  - `StemmerTransformer`
- Query language with support for logical operators (`AND`, `OR`, `NOT`).
- Stress tests

### Changed
- Optimized file handling for large index files.
- Improved integration with Symfony components for seamless installation.

### Fixed
- Corrected index alignment during document updates to ensure data consistency.

---

### Notes

- For upcoming releases, ensure to document all new features, breaking changes, and bug fixes.
- When a release happens, move changes from `[Unreleased]` to a new version section.
