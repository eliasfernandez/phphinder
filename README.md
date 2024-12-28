# PHPhinder  
**A lightweight and modular search engine built in PHP.**  

[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)  
[![Packagist](https://img.shields.io/packagist/v/eliasfernandez/phphinder.svg)](https://packagist.org/packages/eliasfernandez/phphinder)  

---

## Table of Contents  
- [About](#about)  
- [Features](#features)  
- [Installation](#installation)  
- [Usage](#usage)  
- [Configuration](#configuration)  
- [Examples](#examples)  
- [Contributing](#contributing)  
- [License](#license)  

---

## About  
PHPhinder is an open-source, lightweight, and modular search engine designed for PHP applications. It provides powerful search capabilities with a focus on simplicity, speed, and extensibility.  

---

## Features  
- **Full-text search** for indexed documents.  
- **Support for advanced queries**, including prefix queries, AND/OR/NOT combinations, and field-specific searches.  
- **Lightweight and efficient**, with minimal dependencies.  
- **Easy integration** with Symfony or other PHP frameworks.  
- **Highly extensible**, allowing customization of query parsers and indexers.  

---

## Installation  
Install PHPhinder via Composer:  
```bash
composer require eliasfernandez/phphinder
```

---

## Usage  
Here’s a simple example to demonstrate PHPhinder in action:  
```php
use PHPhinder\Index\JsonStorage;
use PHPhinder\SearchEngine;

$storage = new JsonStorage('var');
$engine = new SearchEngine($storage);

$engine->addDocument(['_id' => 1, 'title' => 'Hi', 'text' => 'Hello world!']);
$engine->flush();
$results = $engine->search('Hello');
print_r($results[1]->getDocument());
```

---

## Configuration  
PHPhinder offers several configuration options, including custom analyzers, tokenizers, and more. Refer to the [manual](docs/manual.md) for detailed instructions.  

---

## Contributing  
Contributions are welcome! Please review the [contribution guidelines](CONTRIBUTING.md) before submitting pull requests.  

---

## License  
PHPhinder is open-source software licensed under the [MIT license](LICENSE).  
