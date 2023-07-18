#!/bin/sh -ex

TESTS_DIR=$(dirname $0)
RUN_DIR=${RUN_DIR:-/tmp/cliup-tests-run}

command -v hurl > /dev/null || { echo >&2 "Sorry, you need hurl for that: https://hurl.dev/"; exit 1; }

function _cleanup() {
    rm -rf ${RUN_DIR}
}
trap _cleanup SIGINT EXIT

mkdir -p $RUN_DIR
cd $TESTS_DIR

for s in 1 2 5 10 21 40; do
    test -f "${RUN_DIR}/${s}MB.file" || dd if=/dev/random of="${RUN_DIR}/${s}MB.file" bs=1M count=$s;
done

rm -f ${RUN_DIR}/testsuite.hurl
for s in 1 2 5 10; do
    sed "s/__testfile__/${s}MB.file/g" expect-success.tmpl.hurl >> ${RUN_DIR}/testsuite.hurl;
done
for s in 21 40; do
    sed "s/__testfile__/${s}MB.file/g" expect-failure.tmpl.hurl >> ${RUN_DIR}/testsuite.hurl;
done

cd $RUN_DIR

hurl --fail-at-end --test $(args) testsuite.hurl;
