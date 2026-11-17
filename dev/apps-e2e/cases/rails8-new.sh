# `rails new` (SQLite, Dockerfile, Kamal). config/master.key isn't in the
# repo, so RAILS_MASTER_KEY must be asked for.
# shellcheck shell=sh disable=SC2034
GAPS="G45 G48 G62 G71 G75"

check_main() {
	expect "G45 G71" "/up answers 200" code_is 200 "$APP" /up
	todo "G48 G71" "RAILS_MASTER_KEY asked as a required secret before the first deploy" "needs the import plan"
	todo G62 "storage volume offered; a record survives a redeploy" "needs the import plan and a volume"
	todo G71 "Solid Queue job runs" "needs a job endpoint"
}
