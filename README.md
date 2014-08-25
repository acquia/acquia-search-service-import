# Acquia Search Import PHP Client


This add-on is intended to export a complete Acquia Search Index using command line tools

This repository contains a command line tool that can be run via any *nix-like terminal 
for exporting all or just one Acquia Search index attached to a subscription.

## Installation

### Phar (Recommended for CLI use)

Visit https://github.com/acquia/acquia-search-service-import/releases/latest and download the
latest stable version. The usage examples below assume this method of installation.

## Usage

### To import to 1 specific index using a folder with exports

    ./acquia-search-service-import import --index ABCD-1234 --path "/tmp/acquia_search_export"

## Example

    ./acquia-search-service-import import --index ILMV-27747.dev.mori --path "/tmp/search_export/ILMV-27747"
    [info] Checking if the given subscription has Acquia Search indexes...
    [info] Found 9 Acquia Search indexes.
    Do you want to continue? (y/n). This will DELETE all contents from the ILMV-27747.dev.mori index! ... : y
    [info] Index ILMV-27747.dev.mori has 0 items in the index. Proceeding...
    [info] Sent 200/4728 documents to ILMV-27747.dev.mori
    [info] Sent 400/4728 documents to ILMV-27747.dev.mori
    [info] Sent 600/4728 documents to ILMV-27747.dev.mori
    ...
    [info] Sent 4728/4728 documents to ILMV-27747.dev.mori
    [info] Sent 4728 documents to ILMV-27747.dev.mori
    [info] Index ILMV-27747.dev.mori has 4728 items in the index.

