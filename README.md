# acrcloud
Forked from https://github.com/acrcloud/webapi_example, creating a stable PHP class tagging my whole MP3 collection

# Prerequisites

## Modules
php-pecl-id3: https://pecl.php.net/package/id3

php-pecl-id3 build help: https://stackoverflow.com/questions/21103962/installing-pecl-id3-extension-on-ubuntu (with zend_function_entry id3_functions)

## Additional Programs
bulk-remove ID3 tags before processing: https://www.ghacks.net/2008/12/21/id3-tag-remover/

## Files
ACRCloud.php

automp3-with-acrquery.php

## Folders
/keep

/partial

/unknown

# Assemble List
    $ find | grep -iP '.mp3$' | sort > list

# Pumping list into thing
    cat list | while read -r LINE; do php -f automp3-with-acrquery.php "$LINE"; done

