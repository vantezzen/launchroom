package presets

import (
	"io/fs"
	"regexp"
	"strings"
)

// JVMFacts are read from the Maven/Gradle build and Spring's application config.
type JVMFacts struct {
	// Build is the build file found ("pom.xml", "build.gradle", "build.gradle.kts").
	Build string
	// SpringBoot and Actuator are set when the build uses them.
	SpringBoot bool
	Actuator   bool
	// ContextPath is server.servlet.context-path ("/api"), if set.
	ContextPath string
	// ActuatorBasePath is management.endpoints.web.base-path (default "/actuator").
	ActuatorBasePath string
	// ManagementPort is set when actuator endpoints listen on their own port
	// (management.server.port), which the health check can't reach.
	ManagementPort bool
}

var (
	reSpringProp    = regexp.MustCompile(`(?m)^\s*([a-z.-]+)\s*[=:]\s*(\S+)\s*$`)
	reYAMLContext   = regexp.MustCompile(`(?m)^\s+context-path:\s*['"]?(/[^\s'"#]*)`)
	reYAMLMgmtPort  = regexp.MustCompile(`(?m)^management:\s*\n(?:\s+.*\n)*?\s+server:\s*\n\s+port:`)
	reHTTPPathChars = regexp.MustCompile(`^/[A-Za-z0-9._~/-]*$`)
)

func gatherJVM(fsys fs.FS, f *Facts) {
	var j JVMFacts
	for _, b := range []string{"pom.xml", "build.gradle.kts", "build.gradle"} {
		if s, ok := readSmall(fsys, b); ok {
			j.Build = b
			j.SpringBoot = strings.Contains(s, "org.springframework.boot")
			j.Actuator = strings.Contains(s, "spring-boot-starter-actuator")
			break
		}
	}
	if j.Build == "" {
		return
	}
	j.ActuatorBasePath = "/actuator"
	if s, ok := readSmall(fsys, "src/main/resources/application.properties"); ok {
		for _, m := range reSpringProp.FindAllStringSubmatch(s, -1) {
			switch m[1] {
			case "server.servlet.context-path":
				j.ContextPath = m[2]
			case "management.endpoints.web.base-path":
				j.ActuatorBasePath = m[2]
			case "management.server.port":
				j.ManagementPort = true
			}
		}
	}
	for _, n := range []string{"src/main/resources/application.yml", "src/main/resources/application.yaml"} {
		if s, ok := readSmall(fsys, n); ok {
			if m := reYAMLContext.FindStringSubmatch(s); m != nil && j.ContextPath == "" {
				j.ContextPath = m[1]
			}
			if reYAMLMgmtPort.MatchString(s) {
				j.ManagementPort = true
			}
		}
	}
	if !reHTTPPathChars.MatchString(j.ContextPath) {
		j.ContextPath = ""
	}
	if !reHTTPPathChars.MatchString(j.ActuatorBasePath) {
		j.ActuatorBasePath = "/actuator"
	}
	f.JVM = j
}
