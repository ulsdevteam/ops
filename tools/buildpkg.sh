#!/bin/bash

#
# tools/buildpkg.sh
#
# Copyright (c) 2014-2021 Simon Fraser University
# Copyright (c) 2003-2021 John Willinsky
# Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
#
# Script to create an OPS package for distribution.
#
# Usage: buildpkg.sh <version> [<tag>]
#
#

GITREP=git://github.com/pkp/ops.git

if [ -z "$1" ]; then
	echo "Usage: $0 <version> [<tag>-<branch>]";
	exit 1;
fi

VERSION=$1
TAG=$2
PREFIX=ops
BUILD=$PREFIX-$VERSION
TMPDIR=`mktemp -d $PREFIX.XXXXXX` || exit 1

EXCLUDE="docs/dev									\
tests											\
tools/buildpkg.sh									\
cypress											\
lib/pkp/cypress										\
tools/test										\
lib/pkp/tools/travis									\
lib/pkp/plugins/*/*/tests								\
plugins/*/*/tests									\
plugins/auth/ldap									\
plugins/importexport/sample								\
plugins/importexport/duracloud								\
lib/pkp/tests										\
.git											\
.openshift										\
.scrutinizer.yml									\
.travis.yml										\
lib/pkp/.git										\
lib/pkp/captainhook.json								\
lib/pkp/lib/vendor/smarty/smarty/demo							\
lib/pkp/lib/vendor/alex198710/pnotify/.git						\
lib/pkp/lib/vendor/sebastian								\
lib/pkp/lib/vendor/oyejorge/less.php/test						\
lib/pkp/tools/travis									\
plugins/paymethod/paypal/vendor/clue/stream-filter/examples				\
plugins/paymethod/paypal/vendor/omnipay/common/tests/					\
plugins/paymethod/paypal/vendor/omnipay/paypal/tests/					\
plugins/paymethod/paypal/vendor/guzzle/guzzle/docs/					\
plugins/paymethod/paypal/vendor/guzzle/guzzle/tests/					\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/debug/				\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/console/Tests/			\
plugins/paymethod/paypal/vendor/symfony/http-foundation/Tests/				\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/filesystem/Tests/		\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/stopwatch/Tests/		\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/event-dispatcher/Tests/	\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/config/Tests/			\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/yaml/Tests/			\
plugins/generic/citationStyleLanguage/lib/vendor/guzzle/guzzle/tests/Guzzle/Tests/	\
plugins/generic/citationStyleLanguage/lib/vendor/symfony/config/Tests/			\
plugins/generic/citationStyleLanguage/lib/vendor/citation-style-language/locales/.git	\
lib/pkp/lib/vendor/symfony/translation/Tests/						\
lib/pkp/lib/vendor/symfony/process/Tests/						\
lib/pkp/lib/vendor/pimple/pimple/src/Pimple/Tests/					\
lib/pkp/lib/vendor/robloach/component-installer/tests/ComponentInstaller/Test/		\
lib/pkp/lib/vendor/michelf/php-markdown/test						\
lib/pkp/lib/vendor/adodb/adodb-php/.git							\
plugins/generic/citationStyleLanguage/lib/vendor/satooshi/php-coveralls/tests/		\
plugins/generic/citationStyleLanguage/lib/vendor/guzzle/guzzle/tests/			\
plugins/generic/citationStyleLanguage/lib/vendor/seboettg/collection/tests/		\
plugins/generic/citationStyleLanguage/lib/vendor/seboettg/citeproc-php/tests/		\
plugins/generic/citationStyleLanguage/lib/vendor/seboettg/citeproc-php/example/		\
lib/pkp/lib/vendor/nikic/fast-route/test/						\
lib/pkp/lib/vendor/ezyang/htmlpurifier/tests/						\
lib/pkp/lib/vendor/ezyang/htmlpurifier/smoketests/					\
lib/pkp/lib/vendor/pimple/pimple/ext/pimple/tests/					\
lib/pkp/lib/vendor/robloach/component-installer/tests/					\
lib/pkp/lib/vendor/phpmailer/phpmailer/test/						\
lib/pkp/lib/vendor/swiftmailer/swiftmailer/tests/					\
lib/pkp/lib/vendor/dragonmantank/cron-expression/tests/					\
lib/pkp/lib/vendor/cweagans/composer-patches/tests/					\
lib/pkp/lib/vendor/moxiecode/plupload/examples/						\
node_modules										\
.editorconfig										\
babel.config.js										\
package-lock.json									\
package.json										\
vue.config.js										\
lib/ui-library"


cd $TMPDIR

echo -n "Cloning $GITREP and checking out tag $TAG ... "
git clone -b $TAG --depth 1 -q -n $GITREP $BUILD || exit 1
cd $BUILD
git checkout -q $TAG || exit 1
echo "Done"

echo -n "Checking out corresponding submodules ... "
git submodule -q update --init --recursive >/dev/null || exit 1
echo "Done"

echo "Installing composer dependencies:"
echo -n " - lib/pkp ... "
composer.phar --working-dir=lib/pkp install --no-dev
echo "Done"

echo -n "Installing node dependencies... "
npm install
echo "Done"

echo -n "Running webpack build process... "
npm run build
echo "Done"

echo -n "Preparing package ... "
cp config.TEMPLATE.inc.php config.inc.php
find . \( -name .gitignore -o -name .gitmodules -o -name .keepme \) -exec rm '{}' \;
rm -rf $EXCLUDE
echo "Done"

cd ..

echo -n "Creating archive $BUILD.tar.gz ... "
tar -zhcf ../$BUILD.tar.gz $BUILD
echo "Done"

cd ..

rm -r $TMPDIR
