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

for s in 1 2 5 10 20 21 40; do
  test -f "${RUN_DIR}/${s}MB.file" || dd if=/dev/random of="${RUN_DIR}/${s}MB.file" bs=1M count=$s;
done

rm -f ${RUN_DIR}/testsuite.hurl

# HEAD
cat head.expect-success.hurl > ${RUN_DIR}/head.expect-success.hurl;

# GET
cat get.expect-success.hurl > ${RUN_DIR}/get.expect-success.hurl;

# PUT: SUCCESS
for s in 1 5 10 20; do
  sed "s/__testfile__/${s}MB.file/g" put.expect-success.tmpl.hurl >> ${RUN_DIR}/put.expect-success.hurl;
done
# PUT: FAILURE
for s in 21 40; do
  sed "s/__testfile__/${s}MB.file/g" put.expect-failure.tmpl.hurl >> ${RUN_DIR}/put.expect-failure.hurl;
done

cd $RUN_DIR

hurl --fail-at-end $* --test \
  head.expect-success.hurl \
  get.expect-success.hurl \
  put.expect-success.hurl \
  put.expect-failure.hurl \
;
