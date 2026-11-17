# Rocket's "Hello, world!" from its guide; no port or address configured.
# Hand-written; Cargo.lock from `cargo generate-lockfile`.
# stage: LR_FIXTURE_RUST_IMAGE rs_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

rs_stage() {
	export CARGO_HOME="$HOME/cargo"
	cd /work/app
	cat >Cargo.toml <<TOML
[package]
name = "rust-rocket"
version = "0.1.0"
edition = "2021"

[dependencies]
rocket = "$LR_FIXTURE_ROCKET"
TOML
	mkdir -p src
	cat >src/main.rs <<'RS'
#[macro_use] extern crate rocket;

#[get("/")]
fn index() -> &'static str {
    "Hello, world!"
}

#[launch]
fn rocket() -> _ {
    rocket::build().mount("/", routes![index])
}
RS
	printf '/target\n' >.gitignore
	cargo generate-lockfile
}
