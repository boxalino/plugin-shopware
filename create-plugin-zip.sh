#!/bin/sh

DIR="$( dirname $(realpath $0) )"
cd "$DIR/engine/Shopware/Plugins/Local"
rm "$DIR/boxalino.zip"
zip -r "$DIR/boxalino.zip" Frontend
