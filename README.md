# Contao Migration

A collection of various migration scripts.

## Features
* Convert News Plus modules to [Filter](https://github.com/heimrichhannot/contao-filter-bundle)/[List](https://github.com/heimrichhannot/contao-list-bundle)/[Reader](https://github.com/heimrichhannot/contao-reader-bundle) modules.
* move modules into [blocks](https://github.com/heimrichhannot/contao-blocks)
* move news_categories to [Categories](https://github.com/heimrichhannot/contao-categories-bundle)

## Requires

* Contao 4.4
* [Filter-](https://github.com/heimrichhannot/contao-filter-bundle)/[List-](https://github.com/heimrichhannot/contao-list-bundle)/[Readerbundle](https://github.com/heimrichhannot/contao-reader-bundle)
* [Contao Blocks](https://github.com/heimrichhannot/contao-blocks): ^1.5.2



## Commands

Use [command] --help to get more information.

### News Categories to Categories Bundle

Migration of database entries from news_categories module to heimrichhannot/contao-categories.

Usage:
```
huh:migration:db:news_categories [<field>]
```

Arguments:

Argument | Description
---------|------------
  field  | What is the name of the category field in tl_news (default: categories)? [default: "categories"]


### Move modules to block

Move given module into a block.

Usage:
```
huh:migration:movetoblock [options] [--] <modules> (<modules>)...
```

Arguments:

Argument | Description
---------|------------
modules  |Ids of modules should migrated into a block.

Options:

Option                 | Description
-----------------------|-----
  -b, --block=BLOCK    | Set a block where module should be added to. If not set, a new block is created.
      --ignore-types   | Don't set custom module settings for block module like !autoitem for reader module.
      --dry-run        | Preview command without changing the database.
  -t, --title=TITLE    | Set a block name for new blocks. If not set, name of first module will be used.
      --no-replace     | Don't replace modules with block.
      
      
### Convert News Plus modules to Filter/List/Readerbundle modules

Usage:
```
huh:migration:module:newsplus [options]
```
  
Options:

Option              | Description
--------------------|-----
--dry-run           | Performs a run without writing to datebase and copy templates.
-m, --module=MODULE | Convert a single module instead of all modules.
  
  
### Convert News Plus reader modules to Filter/Readerbundle modules

Migration of tl_module type:newsreader modules to huhreader and creates reader configurations from old tl_module settings.

Usage:
```
migration:module:newsreader [options]
```

Options:

Option              | Description
--------------------|-----
-i, --id[=ID]       |Provide the id of a single module that should be migrated.
-t, --types[=TYPES] | What module types should be migrated? [default: ["newsreader","newsreader_plus"]] (multiple values allowed)