# Contao Migration

A collection of various migration scripts.

## Features
* convert frontend modules to [Filter](https://github.com/heimrichhannot/contao-filter-bundle)/[List](https://github.com/heimrichhannot/contao-list-bundle)/[Reader](https://github.com/heimrichhannot/contao-reader-bundle) modules:
    * News Plus frontend module
    * Owl carousel news list
* move modules into [blocks](https://github.com/heimrichhannot/contao-blocks)
* move news_categories to [Categories](https://github.com/heimrichhannot/contao-categories-bundle)
* Migrations:
    * contao-legacy/fry_accessible_tabs to [heimrichhannot/contao-tab-control-bundle](https://github.com/heimrichhannot/contao-tab-control-bundle)
    

## Requires

* Contao 4.4
* [Filter-](https://github.com/heimrichhannot/contao-filter-bundle)/[List-](https://github.com/heimrichhannot/contao-list-bundle)/[Readerbundle](https://github.com/heimrichhannot/contao-reader-bundle)
* [Contao Blocks](https://github.com/heimrichhannot/contao-blocks): ^1.5.2



## Commands

Use [command] --help to get more information.


### Modules

#### Convert News Archive Menu to Filter Bundle 

Usage: 
```
huh:migration:module:newsmenu [options]
```

Options:

Option              | Description
--------------------|-----
-i, --ids[=IDS]     | Provide the id of a single module or a comma separated list of module ids that should be migrated.
-t, --types[=TYPES] | What module types should be migrated? [default: ["newsmenu"]] (multiple values allowed)
--dry-run           | Performs a run without writing to database and copy templates.

Since: `0.4.0`

#### Convert Owl Carousel News List to Filter/List modules

Usage: 
```
huh:migration:module:owlcarousel [options]
```

Options:

Option              | Description
--------------------|-----
-i, --ids[=IDS]     | Provide the id of a single module or a comma separated list of module ids that should be migrated.
-t, --types[=TYPES] | What module types should be migrated? [default: ["owl_newslist"]] (multiple values allowed)
--dry-run           | Performs a run without writing to database and copy templates.

Since: `0.2.0`

#### Convert News Plus modules to Filter/List/Readerbundle modules

Usage:
```
huh:migration:module:newsplus [options]
```
  
Options:

Option              | Description
--------------------|-----
--dry-run           | Performs a run without writing to database and copy templates.
-m, --module=MODULE | Convert a single module instead of all modules.

#### Convert News Plus reader modules to Filter/Readerbundle modules

Migration of tl_module type:newsreader modules to huhreader and creates reader configurations from old tl_module settings.

Usage:
```
migration:module:newsreader [options]
```

Options:

Option              | Description
--------------------|-----
-i, --ids[=IDS]     | Provide the id of a single module or a comma separated list of module ids that should be migrated.
-t, --types[=TYPES] | What module types should be migrated? [default: ["newsreader","newsreader_plus"]] (multiple values allowed)
--dry-run           | Performs a run without writing to database and copy templates.



### Content elements

#### Convert Tabs to Tab Control Bundle

Supported source modules:
* contao-legacy/fry_accessible_tabs

Usage: 
```
huh:migration:ce:tab_control_bundle [options]
```

Options:

Option              | Description
--------------------|-----
-i, --ids[=IDS]     | Provide the id of a single module or a comma separated list of module ids that should be migrated.
-t, --types[=TYPES] | What content element types should be migrated? [default: ["owl_newslist"]] (multiple values allowed)
--dry-run           | Performs a run without writing to database and copy templates.

Since: `0.3.0`



### Others


#### News Categories to Categories Bundle

Migration of database entries from news_categories module to heimrichhannot/contao-categories.

Usage:
```
huh:migration:db:news_categories [<field>] [options]
```

Arguments:

Argument | Description
---------|------------
 field | What is the name of the category field in tl_news? [default: "categories"]
 
Options:

Option                   | Description
-------------------------|------------
--category-ids           | Restrict the command to legacy news categories of certain IDs **and their children** [default: no restriction]
--news-archive-ids       | Restrict the command to news of certain archives [default: no restriction]
--primary-category-field | Pass in the name of the *source* field in tl_news holding the ID of the primary category.


#### Move modules to block

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
