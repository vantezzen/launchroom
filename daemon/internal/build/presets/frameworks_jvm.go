package presets

import (
	"strings"
)

type jvm struct{}

func (jvm) Name() string { return "jvm" }
func (jvm) Version() int { return 1 }

func (jvm) Match(f Facts, i Info) bool {
	return i.Provider == "java" || (i.Provider == "" && f.JVM.Build != "")
}

// actuatorHealthPath joins the context path, the actuator base path and /health.
func actuatorHealthPath(j JVMFacts) string {
	p := strings.TrimSuffix(j.ContextPath, "/") + strings.TrimSuffix(j.ActuatorBasePath, "/") + "/health"
	if !strings.HasPrefix(p, "/") {
		p = "/" + p
	}
	return p
}

func (jvm) Propose(f Facts, i Info) Proposal {
	j := f.JVM
	spring := j.SpringBoot || i.Metadata["javaFramework"] == "spring-boot"
	p := Proposal{Framework: "java", HealthPath: "/"}
	if spring {
		p.Framework = "spring-boot"
	}
	if i.Dockerfile {
		p.Port = 8080
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "jvm-memory", Title: "Size the heap to the memory limit",
		Detail: "The JVM uses up to 75% of the service's memory for its heap (its default is 25%) and exits on OutOfMemoryError, so launchroom restarts it instead of leaving it broken.",
		Effect: Effect{RuntimeEnv: map[string]string{"JAVA_TOOL_OPTIONS": "-XX:MaxRAMPercentage=75 -XX:+ExitOnOutOfMemoryError"}},
	})
	if spring {
		p.Decisions = append(p.Decisions, Decision{
			ID: "forward-headers", Title: "Trust launchroom's proxy",
			Detail: "SERVER_FORWARD_HEADERS_STRATEGY=native, so redirects and generated links use https and your domain.",
			Effect: Effect{RuntimeEnv: map[string]string{"SERVER_FORWARD_HEADERS_STRATEGY": "native"}},
		})
		if j.Actuator && !j.ManagementPort {
			path := actuatorHealthPath(j)
			p.HealthPath = path
			p.Decisions = append(p.Decisions, Decision{
				ID: "health-actuator", Title: "Health check on " + path, Detail: "Spring Boot Actuator's health endpoint.",
				Effect: Effect{HealthPath: path},
			})
		}
	}
	return p
}
