# Spring Boot from Spring Initializr (start.spring.io): Maven, Java 21, Web
# and Actuator, at a pinned Boot version.
# stage: LR_FIXTURE_CURL_IMAGE curl_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

curl_stage() {
	cd /work/tmp
	curl -fsSL -o starter.tgz "https://start.spring.io/starter.tgz?type=maven-project&language=java&bootVersion=$LR_FIXTURE_SPRING_BOOT&baseDir=$FIXTURE&groupId=com.example&artifactId=demo&name=demo&packageName=com.example.demo&javaVersion=21&dependencies=web,actuator"
	tar -xzf starter.tgz
	rm starter.tgz
	adopt "$FIXTURE"
}
