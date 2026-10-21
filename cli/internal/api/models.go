package api

import "encoding/json"

// Token is the API token of the current request (GET /me).
type Token struct {
	ID         string   `json:"id"`
	Name       string   `json:"name"`
	Abilities  []string `json:"abilities"`
	ExpiresAt  string   `json:"expiresAt"`
	LastUsedAt *string  `json:"lastUsedAt"`
	Client     string   `json:"client"`
}

// User is the token's owner.
type User struct {
	Name  string `json:"name"`
	Email string `json:"email"`
}

// Team is the team a token is bound to.
type Team struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Role string `json:"role,omitempty"`
}

// Me is GET /me.
type Me struct {
	Token Token `json:"token"`
	User  User  `json:"user"`
	Team  Team  `json:"team"`
}

// EnvironmentRef is an environment inside a project listing.
type EnvironmentRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug"`
	Kind string `json:"kind"`
}

// Project is a project of the token's team.
type Project struct {
	ID           string           `json:"id"`
	Name         string           `json:"name"`
	Slug         string           `json:"slug"`
	CreatedAt    string           `json:"createdAt"`
	Environments []EnvironmentRef `json:"environments"`
}

// Environment is one environment of a project.
type Environment struct {
	ID               string `json:"id"`
	ProjectID        string `json:"projectId"`
	Name             string `json:"name"`
	Slug             string `json:"slug"`
	Kind             string `json:"kind"`
	Revision         *int   `json:"revision"`
	AutoDeployPaused bool   `json:"autoDeployPaused"`
	DashboardURL     string `json:"dashboardUrl"`
}

// Commit is the commit a deployment builds.
type Commit struct {
	Sha      string  `json:"sha"`
	ShortSha string  `json:"shortSha"`
	Message  *string `json:"message"`
	Author   *string `json:"author"`
	Ref      *string `json:"ref"`
}

// Failure explains a failed deployment.
type Failure struct {
	Class   string `json:"class"`
	Message string `json:"message"`
}

// Deployment is one deployment of a service in an environment.
type Deployment struct {
	ID            string   `json:"id"`
	EnvironmentID string   `json:"environmentId"`
	ServiceID     string   `json:"serviceId"`
	ServiceName   string   `json:"serviceName"`
	Status        string   `json:"status"`
	Finished      bool     `json:"finished"`
	Trigger       string   `json:"trigger"`
	Commit        *Commit  `json:"commit"`
	Failure       *Failure `json:"failure"`
	QueuedAt      string   `json:"queuedAt"`
	StartedAt     *string  `json:"startedAt"`
	FinishedAt    *string  `json:"finishedAt"`
	DashboardURL  string   `json:"dashboardUrl"`
}

// Failed reports whether a finished deployment did not go live.
func (d Deployment) Failed() bool {
	return d.Status == "failed" || d.Status == "cancelled"
}

// Service is a service in one environment.
type Service struct {
	ID               string      `json:"id"`
	EnvironmentID    string      `json:"environmentId"`
	Name             string      `json:"name"`
	Slug             string      `json:"slug"`
	Kind             string      `json:"kind"`
	Status           string      `json:"status"`
	ActiveDeployment *Deployment `json:"activeDeployment"`
	LatestDeployment *Deployment `json:"latestDeployment"`
	URLs             []string    `json:"urls"`
	DashboardURL     string      `json:"dashboardUrl"`
	// Volumes are the service's volumes, when the dashboard lists them.
	Volumes []VolumeRef `json:"volumes,omitempty"`
}

// DeployResult is POST /environments/{env}/deploy.
type DeployResult struct {
	Revision    int          `json:"revision"`
	Deployments []Deployment `json:"deployments"`
}

// LogLine is one line of a deployment's log.
type LogLine struct {
	Number  int     `json:"number"`
	Time    string  `json:"time"`
	Stream  string  `json:"stream"`
	Text    string  `json:"text"`
	Section string  `json:"section"`
	Step    *string `json:"step"`
}

// LogEntry is one line of an environment's logs.
type LogEntry struct {
	ID           string  `json:"id"`
	Time         string  `json:"time"`
	Level        string  `json:"level"`
	Source       string  `json:"source"`
	Service      string  `json:"service"`
	ServiceID    *string `json:"serviceId"`
	Replica      *string `json:"replica"`
	Server       *string `json:"server"`
	DeploymentID *string `json:"deploymentId"`
	Stream       string  `json:"stream"`
	Message      string  `json:"message"`
}

// LogMeta is the pagination of a log page.
type LogMeta struct {
	Cursor    int  `json:"cursor"`
	HasMore   bool `json:"hasMore"`
	Finished  bool `json:"finished"`
	Truncated bool `json:"truncated"`
}

// VariableTarget is where a variable applies.
type VariableTarget struct {
	EnvironmentID *string `json:"environmentId"`
	AllPreviews   bool    `json:"allPreviews"`
	BranchPattern *string `json:"branchPattern"`
}

// Variable is a variable row of a service in an environment. Secret
// values are never part of a listing (Value is nil).
type Variable struct {
	ID         string           `json:"id"`
	Key        string           `json:"key"`
	Type       string           `json:"type"`
	Value      *string          `json:"value"`
	Targets    []VariableTarget `json:"targets"`
	Connection bool             `json:"connection"`
	Editable   bool             `json:"editable"`
}

// ResolvedEnv is GET …/env: the resolved variables of a service.
type ResolvedEnv struct {
	Variables map[string]string `json:"variables"`
	Withheld  []string          `json:"withheld"`
	Revision  *int              `json:"revision"`
}

// LoginSession is POST /cli/sessions.
type LoginSession struct {
	DeviceCode              string `json:"deviceCode"`
	UserCode                string `json:"userCode"`
	VerificationURL         string `json:"verificationUrl"`
	VerificationURLComplete string `json:"verificationUrlComplete"`
	ExpiresIn               int    `json:"expiresIn"`
	Interval                int    `json:"interval"`
}

// LoginToken is the approved result of POST /cli/sessions/token.
type LoginToken struct {
	Token     string   `json:"token"`
	ExpiresAt string   `json:"expiresAt"`
	Team      Team     `json:"team"`
	Abilities []string `json:"abilities"`
	User      User     `json:"user"`
}

// envelope is {"data": …, "meta": …}.
type envelope struct {
	Data json.RawMessage `json:"data"`
	Meta json.RawMessage `json:"meta"`
}

// ListMeta is the cursor of a list response.
type ListMeta struct {
	NextCursor *string `json:"nextCursor"`
}

// ConfigExport is GET …/config: the environment as launchroom.json
// (docs/18 §4).
type ConfigExport struct {
	Path     string `json:"path"`
	Content  string `json:"content"`
	Revision *int   `json:"revision"`
}

// ConfigExcerptLine is one line of the file around a problem.
type ConfigExcerptLine struct {
	Number int    `json:"number"`
	Text   string `json:"text"`
}

// ConfigProblem is one problem of a launchroom.json, with its position.
type ConfigProblem struct {
	Message string              `json:"message"`
	Line    *int                `json:"line"`
	Column  *int                `json:"column"`
	Path    *string             `json:"path"`
	Hint    *string             `json:"hint"`
	Excerpt []ConfigExcerptLine `json:"excerpt"`
}

// ChangeItem is one staged change a launchroom.json would produce.
type ChangeItem struct {
	Path      string  `json:"path"`
	Kind      string  `json:"kind"`
	Effect    string  `json:"effect"`
	Label     string  `json:"label"`
	ServiceID *string `json:"serviceId"`
	Before    *string `json:"before"`
	After     *string `json:"after"`
}

// ConfigCheck is POST …/config/validate and …/config/diff.
type ConfigCheck struct {
	Valid    bool            `json:"valid"`
	Errors   []ConfigProblem `json:"errors"`
	Warnings []ConfigProblem `json:"warnings"`
	Ignored  []ConfigProblem `json:"ignored"`
	Changes  []ChangeItem    `json:"changes"`
}
