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

    ./acquia-search-service-import import ABCD-12345 "/tmp/acquia_search_export"

### To import to 1 specific index using a tarball with exports

    ./acquia-search-service-import import ABCD-12345 "/tmp/as_export/ABCD-12345/ABCD-12345-1408965924.tar.gz"

### To import the latest tarball in a directory based on the write time

    ./acquia-search-service-import import ILMV-27747.dev.mori `ls -t /tmp/as_export/ILMV-27747/ILMV-27747-*.tar.gz | head -1`

### To import the latest tarball in a directory based on the filename

    ./acquia-search-service-import import ILMV-27747.dev.mori `ls -t /tmp/as_export/ILMV-27747/ILMV-27747-*.tar.gz | sort -r | head -1`

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

