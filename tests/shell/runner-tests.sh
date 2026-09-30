#!/bin/sh

set -u

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
RUNNER="$ROOT/payload/bin/zcd-run"

PASSED=0
FAILED=0
SKIPPED=0
CURRENT=""
BASE=""

TOOLBOX=$(mktemp -d 2>/dev/null) || TOOLBOX=$(mktemp -d -t zcdtools)

link_tool() {
    command -v "$1" >/dev/null 2>&1 && return 0

    for _candidate in "$2" /opt/homebrew/opt/util-linux/bin/"$1" /usr/local/opt/util-linux/bin/"$1"; do
        if [ -n "$_candidate" ] && [ -x "$_candidate" ]; then
            ln -sf "$_candidate" "$TOOLBOX/$1"
            return 0
        fi
    done

    return 1
}

if [ -z "${ZCD_TEST_NO_TOOLS:-}" ]; then
    link_tool timeout "$(command -v gtimeout 2>/dev/null || true)"
    link_tool setsid ""
    link_tool flock ""
fi

PATH="$TOOLBOX:$PATH"
export PATH

printf 'tooling: timeout=%s setsid=%s flock=%s\n\n' \
    "$(command -v timeout >/dev/null 2>&1 && echo yes || echo no)" \
    "$(command -v setsid >/dev/null 2>&1 && echo yes || echo no)" \
    "$(command -v flock >/dev/null 2>&1 && echo yes || echo no)"

green() { printf '\033[32m%s\033[0m' "$1"; }
red() { printf '\033[31m%s\033[0m' "$1"; }
yellow() { printf '\033[33m%s\033[0m' "$1"; }

start() {
    CURRENT=$1
    printf '  %s\n' "$CURRENT"
}

ok() {
    PASSED=$((PASSED + 1))
    printf '    %s %s\n' "$(green '✔')" "$1"
}

nok() {
    FAILED=$((FAILED + 1))
    printf '    %s %s\n      %s\n' "$(red '✘')" "$1" "$2"
}

skip() {
    SKIPPED=$((SKIPPED + 1))
    printf '    %s %s\n      %s\n' "$(yellow '−')" "$1" "$2"
}

have_tool() {
    command -v "$1" > /dev/null 2>&1
}

can_stop_a_process_group() {
    have_tool timeout || have_tool setsid
}

assert_eq() {
    if [ "$1" = "$2" ]; then
        ok "$3"
    else
        nok "$3" "expected [$1] got [$2]"
    fi
}

assert_contains() {
    case "$2" in
        *"$1"*) ok "$3" ;;
        *) nok "$3" "expected to find [$1] in [$2]" ;;
    esac
}


assert_file_exists() {
    if [ -f "$1" ]; then
        ok "$2"
    else
        nok "$2" "missing file $1"
    fi
}

setup() {
    unset ZCD_DISABLE_FLOCK ZCD_DISABLE_TIMEOUT ZCD_DISABLE_SETSID

    BASE=$(mktemp -d 2>/dev/null) || BASE=$(mktemp -d -t zcd)
    mkdir -p "$BASE/bin" "$BASE/jobs" "$BASE/runs" "$BASE/state" "$BASE/locks"
    cp "$RUNNER" "$BASE/bin/zcd-run"
    chmod 700 "$BASE" "$BASE/bin" "$BASE/jobs" "$BASE/runs" "$BASE/state" "$BASE/locks"
    chmod 700 "$BASE/bin/zcd-run"
}

teardown() {
    [ -n "$BASE" ] && [ -d "$BASE" ] && rm -rf "$BASE"
    BASE=""
}

make_job() {
    _id=$1
    _command=$2

    printf '%s\n' "$_command" > "$BASE/jobs/$_id.cmd"
    chmod 600 "$BASE/jobs/$_id.cmd"
    : > "$BASE/jobs/$_id.conf"
    chmod 600 "$BASE/jobs/$_id.conf"
}

set_conf() {
    printf '%s=%s\n' "$2" "$3" >> "$BASE/jobs/$1.conf"
}

run_job() {
    "$BASE/bin/zcd-run" "$@"
}

latest_meta() {
    _dir="$BASE/runs/$1"
    _found=""

    for _candidate in "$_dir"/*.json; do
        [ -f "$_candidate" ] || continue
        _found=$_candidate
    done

    printf '%s' "$_found"
}

meta_number() {
    sed -n 's/.*"'"$2"'"[[:space:]]*:[[:space:]]*\([0-9-][0-9]*\).*/\1/p' "$1" 2>/dev/null | head -n 1
}

meta_text() {
    sed -n 's/.*"'"$2"'"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -n 1
}

state_number() {
    sed -n 's/.*"'"$2"'"[[:space:]]*:[[:space:]]*\([0-9-][0-9]*\).*/\1/p' "$BASE/state/$1.json" 2>/dev/null | head -n 1
}

count_runs() {
    _total=0

    for _candidate in "$BASE/runs/$1"/*.json; do
        [ -f "$_candidate" ] || continue
        _total=$((_total + 1))
    done

    printf '%s' "$_total"
}

JOB_A=0123456789abcdef
JOB_B=fedcba9876543210

start "reports its version and capabilities"
setup
assert_eq "1.0.0" "$(run_job --version)" "version is reported"
CAPS=$(run_job --check)
assert_contains "runner_version=1.0.0" "$CAPS" "capability report names the version"
assert_contains "uid=$(id -u)" "$CAPS" "capability report names the uid"
teardown

start "a successful job is recorded and stays silent"
setup
make_job "$JOB_A" "printf 'hello from the job\n'"
OUTPUT=$(run_job "$JOB_A" 2>&1)
STATUS=$?
assert_eq "0" "$STATUS" "exit code is zero"
assert_eq "" "$OUTPUT" "nothing is written to cron"
META=$(latest_meta "$JOB_A")
assert_file_exists "$META" "a run record was written"
assert_eq "0" "$(meta_number "$META" exit_code)" "exit code is recorded"
assert_eq "0" "$(meta_number "$META" skipped)" "the run is not marked skipped"
assert_contains "hello from the job" "$(cat "${META%.json}.out")" "output was captured"
teardown

start "a failing job reports its output to cron"
setup
make_job "$JOB_A" "printf 'something broke\n' >&2; exit 3"
OUTPUT=$(run_job "$JOB_A" 2>&1)
STATUS=$?
assert_eq "3" "$STATUS" "the job exit code is preserved"
assert_contains "something broke" "$OUTPUT" "captured output is emitted"
assert_contains "exited 3" "$OUTPUT" "a summary line is emitted"
META=$(latest_meta "$JOB_A")
assert_eq "3" "$(meta_number "$META" exit_code)" "exit code is recorded"
assert_eq "1" "$(state_number "$JOB_A" consecutive_failures)" "failure streak starts at one"
teardown

start "unusual exit codes survive unchanged"
setup
make_job "$JOB_A" "exit 42"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "42" "$?" "exit code 42 is preserved"
teardown

start "the failure streak grows and then resets"
setup
make_job "$JOB_A" "exit 1"
run_job "$JOB_A" >/dev/null 2>&1
run_job "$JOB_A" >/dev/null 2>&1
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "3" "$(state_number "$JOB_A" consecutive_failures)" "three failures counted"
make_job "$JOB_A" "exit 0"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$(state_number "$JOB_A" consecutive_failures)" "a success resets the streak"
teardown

start "emit modes are honoured"
setup
make_job "$JOB_A" "printf 'noisy\n'; exit 1"
set_conf "$JOB_A" emit never
assert_eq "" "$(run_job "$JOB_A" 2>&1)" "emit=never stays silent on failure"
teardown
setup
make_job "$JOB_A" "printf 'all good\n'"
set_conf "$JOB_A" emit always
assert_contains "all good" "$(run_job "$JOB_A" 2>&1)" "emit=always reports a success"
teardown

start "standard input is delivered to the job"
setup
make_job "$JOB_A" "cat"
printf 'first line\nsecond line\n' > "$BASE/jobs/$JOB_A.in"
chmod 600 "$BASE/jobs/$JOB_A.in"
run_job "$JOB_A" >/dev/null 2>&1
META=$(latest_meta "$JOB_A")
assert_contains "second line" "$(cat "${META%.json}.out")" "stdin reached the job"
teardown

start "a job with no stdin file cannot hang on the terminal"
setup
make_job "$JOB_A" "cat; printf 'done\n'"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$?" "the job finished without blocking"
teardown

start "concurrent runs are skipped while the lock is held"
setup
make_job "$JOB_A" "sleep 2"
run_job "$JOB_A" >/dev/null 2>&1 &
FIRST=$!
sleep 0.4
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$?" "the skipped run exits quietly"
assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" skipped)" "the skipped run is recorded"
wait "$FIRST"
teardown

start "overlap is allowed when configured"
setup
make_job "$JOB_A" "sleep 1"
set_conf "$JOB_A" on_overlap allow
run_job "$JOB_A" >/dev/null 2>&1 &
FIRST=$!
sleep 0.3
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$(meta_number "$(latest_meta "$JOB_A")" skipped)" "the second run was not skipped"
wait "$FIRST"
teardown

start "locking works without flock"
setup
make_job "$JOB_A" "sleep 2"
env ZCD_DISABLE_FLOCK=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1 &
FIRST=$!
sleep 0.4
env ZCD_DISABLE_FLOCK=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" skipped)" "the directory lock also prevents overlap"
assert_eq "directory" "$(meta_text "$(latest_meta "$JOB_A")" lock_style)" "the directory lock style is recorded"
wait "$FIRST"
teardown

start "a lock left behind by a dead process is broken"
setup
make_job "$JOB_A" "printf 'ran anyway\n'"
mkdir -p "$BASE/locks/$JOB_A.lockdir"
printf '999999\n' > "$BASE/locks/$JOB_A.lockdir/pid"
env ZCD_DISABLE_FLOCK=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$?" "the run completed"
META=$(latest_meta "$JOB_A")
assert_eq "0" "$(meta_number "$META" skipped)" "the run was not skipped"
assert_eq "1" "$(meta_number "$META" stale_lock_broken)" "breaking the stale lock is recorded"
teardown

start "the lock is released when the job finishes"
setup
make_job "$JOB_A" "exit 0"
env ZCD_DISABLE_FLOCK=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
if [ -d "$BASE/locks/$JOB_A.lockdir" ]; then
    nok "the lock directory is removed" "lock directory still present"
else
    ok "the lock directory is removed"
fi
teardown

start "a job that exceeds its timeout is stopped"
setup
make_job "$JOB_A" "sleep 30"
set_conf "$JOB_A" timeout_seconds 1
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "124" "$?" "the timeout exit code is returned"
META=$(latest_meta "$JOB_A")
assert_eq "1" "$(meta_number "$META" timed_out)" "the timeout is recorded"
teardown

start "the timeout works without the timeout binary"
setup
make_job "$JOB_A" "sleep 30"
set_conf "$JOB_A" timeout_seconds 1
env ZCD_DISABLE_TIMEOUT=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "124" "$?" "the watchdog returns the timeout exit code"
assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" timed_out)" "the watchdog records the timeout"
teardown


start "the watchdog does not add a delay to every run"
setup
make_job "$JOB_A" "exit 0"
set_conf "$JOB_A" timeout_seconds 30
STARTED=$(date +%s)
ROUND=0
while [ "$ROUND" -lt 5 ]; do
    env ZCD_DISABLE_TIMEOUT=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
    ROUND=$((ROUND + 1))
done
ELAPSED=$(( $(date +%s) - STARTED ))
if [ "$ELAPSED" -le 3 ]; then
    ok "five watched runs finished in ${ELAPSED}s"
else
    nok "five watched runs finish promptly" "took ${ELAPSED}s for five runs"
fi
teardown

start "a timed out job does not leave its work running"
setup
MARKER="$BASE/still-running"
cat > "$BASE/jobs/$JOB_A.cmd" <<CMD
( sleep 6; touch "$MARKER" ) &
wait
CMD
chmod 600 "$BASE/jobs/$JOB_A.cmd"
: > "$BASE/jobs/$JOB_A.conf"
chmod 600 "$BASE/jobs/$JOB_A.conf"
set_conf "$JOB_A" timeout_seconds 1
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "124" "$?" "the job timed out"

if can_stop_a_process_group; then
    assert_eq "0" "$(meta_number "$(latest_meta "$JOB_A")" orphans_possible)" "the runner reports no orphans"
    sleep 8

    if [ -f "$MARKER" ]; then
        nok "background work is stopped with the job" "the marker file was created after the timeout"
    else
        ok "background work is stopped with the job"
    fi
else
    assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" orphans_possible)" "the runner admits orphans are possible"
    skip "background work is stopped with the job" "needs timeout or setsid, neither is installed"
fi
teardown

start "the watchdog also stops background work"
setup
MARKER="$BASE/still-running"
cat > "$BASE/jobs/$JOB_A.cmd" <<CMD
( sleep 6; touch "$MARKER" ) &
wait
CMD
chmod 600 "$BASE/jobs/$JOB_A.cmd"
: > "$BASE/jobs/$JOB_A.conf"
chmod 600 "$BASE/jobs/$JOB_A.conf"
set_conf "$JOB_A" timeout_seconds 1
env ZCD_DISABLE_TIMEOUT=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "124" "$?" "the job timed out"

if have_tool setsid; then
    assert_eq "0" "$(meta_number "$(latest_meta "$JOB_A")" orphans_possible)" "the setsid watchdog reports no orphans"
    sleep 8

    if [ -f "$MARKER" ]; then
        nok "the watchdog stops background work too" "the marker file was created after the timeout"
    else
        ok "the watchdog stops background work too"
    fi
else
    assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" orphans_possible)" "the watchdog admits orphans are possible"
    skip "the watchdog stops background work too" "needs setsid, which is not installed"
fi
teardown

start "the runner admits when it cannot guarantee a clean stop"
setup
make_job "$JOB_A" "sleep 30"
set_conf "$JOB_A" timeout_seconds 1
env ZCD_DISABLE_TIMEOUT=1 ZCD_DISABLE_SETSID=1 "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "124" "$?" "the job still times out"
assert_eq "1" "$(meta_number "$(latest_meta "$JOB_A")" orphans_possible)" "the limitation is recorded honestly"
CAPS=$(env ZCD_DISABLE_TIMEOUT=1 ZCD_DISABLE_SETSID=1 "$BASE/bin/zcd-run" --check)
assert_contains "timeout_is_reliable=0" "$CAPS" "the capability report warns about timeouts"
teardown

start "large output is truncated to the configured limit"
setup
make_job "$JOB_A" "awk 'BEGIN { while (i++ < 4000) print \"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\" }'"
set_conf "$JOB_A" output_limit_bytes 4096
run_job "$JOB_A" >/dev/null 2>&1
META=$(latest_meta "$JOB_A")
assert_eq "1" "$(meta_number "$META" truncated)" "truncation is recorded"
SIZE=$(wc -c < "${META%.json}.out" | tr -d ' ')
if [ "$SIZE" -lt 6000 ]; then
    ok "the stored output is bounded"
else
    nok "the stored output is bounded" "stored $SIZE bytes"
fi
assert_contains "output truncated" "$(cat "${META%.json}.out")" "a truncation notice is stored"
teardown

start "run history is pruned to the retention limit"
setup
make_job "$JOB_A" "exit 0"
set_conf "$JOB_A" retain_runs 3
INDEX=0
while [ "$INDEX" -lt 6 ]; do
    run_job "$JOB_A" >/dev/null 2>&1
    INDEX=$((INDEX + 1))
    sleep 1
done
assert_eq "3" "$(count_runs "$JOB_A")" "only the newest runs are kept"
teardown

start "durations stay sane when date has no nanoseconds"
setup
BSDDATE=$(mktemp -d 2>/dev/null) || BSDDATE=$(mktemp -d -t zcdbsd)
REALDATE=$(command -v date)
cat > "$BSDDATE/date" <<EOF
#!/bin/sh
if [ "\${1:-}" = "+%s%N" ]; then
    printf '%sN' "\$($REALDATE +%s)"
    exit 0
fi
exec $REALDATE "\$@"
EOF
chmod 755 "$BSDDATE/date"
make_job "$JOB_A" "exit 0"
env PATH="$BSDDATE:$PATH" "$BASE/bin/zcd-run" "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$?" "the run still succeeds"
META=$(latest_meta "$JOB_A")
DURATION=$(meta_number "$META" duration_ms)
if [ -n "$DURATION" ] && [ "$DURATION" -ge 0 ] && [ "$DURATION" -le 2000 ]; then
    ok "the recorded duration is sane (${DURATION} ms)"
else
    nok "the recorded duration is sane" "got [$DURATION]"
fi
STARTED=$(meta_number "$META" started_at)
if [ -n "$STARTED" ] && [ "$STARTED" -gt 1000000000 ]; then
    ok "the start time is still recorded"
else
    nok "the start time is still recorded" "got [$STARTED]"
fi
rm -rf "$BSDDATE"
teardown

start "invalid identifiers are refused"
setup
for BAD in "" "short" "../../etc/passwd" "0123456789ABCDEF" "0123456789abcdefg" "0123456789abcde;"; do
    run_job "$BAD" >/dev/null 2>&1
    assert_eq "78" "$?" "refused [$BAD]"
done
teardown

start "a missing command file is refused"
setup
run_job "$JOB_B" >/dev/null 2>&1
assert_eq "78" "$?" "the run is refused"
teardown

start "a base directory other users can write to is refused"
setup
make_job "$JOB_A" "exit 0"
chmod 707 "$BASE"
MESSAGE=$(run_job "$JOB_A" 2>&1)
assert_eq "78" "$?" "the run is refused"
assert_contains "writable by other users" "$MESSAGE" "the reason is explained"
chmod 700 "$BASE"
teardown

start "a group writable base directory is refused"
setup
make_job "$JOB_A" "exit 0"
chmod 770 "$BASE"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "78" "$?" "the run is refused"
chmod 700 "$BASE"
teardown

start "a group writable command file is refused"
setup
make_job "$JOB_A" "exit 0"
chmod 620 "$BASE/jobs/$JOB_A.cmd"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "78" "$?" "the run is refused"
teardown

DOLLAR='$'
TICK='`'

start "the command is interpreted exactly once"
setup
make_job "$JOB_A" "true"
cat > "$BASE/jobs/$JOB_A.cmd" <<'CMD'
printf '%s\n' 'a;b$(whoami)`id`|c>d'
CMD
chmod 600 "$BASE/jobs/$JOB_A.cmd"
run_job "$JOB_A" >/dev/null 2>&1
META=$(latest_meta "$JOB_A")
EXPECTED="a;b${DOLLAR}(whoami)${TICK}id${TICK}|c>d"
assert_contains "$EXPECTED" "$(cat "${META%.json}.out")" "the literal text was printed, not evaluated"
teardown

start "settings are never evaluated as shell"
setup
make_job "$JOB_A" "exit 1"
CANARY="$BASE/canary"
printf 'name=%s(touch %s)\n' "$DOLLAR" "$CANARY" >> "$BASE/jobs/$JOB_A.conf"
run_job "$JOB_A" >/dev/null 2>&1
if [ -e "$CANARY" ]; then
    nok "a crafted job name is not executed" "the canary file was created"
else
    ok "a crafted job name is not executed"
fi
teardown

start "a crafted timeout setting cannot break the run"
setup
make_job "$JOB_A" "exit 0"
printf 'timeout_seconds=%s(exit 9)\n' "$DOLLAR" >> "$BASE/jobs/$JOB_A.conf"
run_job "$JOB_A" >/dev/null 2>&1
assert_eq "0" "$?" "the invalid timeout falls back to no timeout"
teardown

[ -n "$TOOLBOX" ] && [ -d "$TOOLBOX" ] && rm -rf "$TOOLBOX"

printf '\n'

if [ "$SKIPPED" -gt 0 ]; then
    SUMMARY="$PASSED passed, $FAILED failed, $SKIPPED skipped"
else
    SUMMARY="$PASSED passed, $FAILED failed"
fi

if [ "$FAILED" -eq 0 ]; then
    printf '\033[42;30m PASS \033[0m  %s\n' "$SUMMARY"
    exit 0
fi

printf '\033[41;37m FAIL \033[0m  %s\n' "$SUMMARY"
exit 1
