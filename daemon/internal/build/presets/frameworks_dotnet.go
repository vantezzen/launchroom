package presets

type dotnet struct{}

func (dotnet) Name() string { return "dotnet" }
func (dotnet) Version() int { return 1 }

func (dotnet) Match(f Facts, i Info) bool {
	return i.Provider == "dotnet" || (i.Provider == "" && f.DotNet.Project != "")
}

func (dotnet) Propose(f Facts, i Info) Proposal {
	p := Proposal{Framework: "dotnet", HealthPath: "/"}
	if f.DotNet.Web || i.Provider == "dotnet" {
		p.Framework = "aspnetcore"
	}
	if i.Dockerfile {
		// .NET 8+ images listen on 8080 (ASPNETCORE_HTTP_PORTS).
		p.Port = 8080
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "forwarded-headers", Title: "Trust launchroom's proxy",
		Detail: "ASPNETCORE_FORWARDEDHEADERS_ENABLED=true, so Request.Scheme is https and redirects and sign-in callbacks use your domain.",
		Effect: Effect{RuntimeEnv: map[string]string{"ASPNETCORE_FORWARDEDHEADERS_ENABLED": "true"}},
	})
	return p
}
