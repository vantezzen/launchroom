# Gin's quick start (GET /ping, r.Run() with no address). Hand-written;
# go.mod and go.sum from `go mod init` and `go mod tidy`.
# stage: LR_GO_IMAGE go_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

go_stage() {
	export GOPATH="$HOME/go" GOCACHE="$HOME/cache" GOFLAGS=-modcacherw GOTOOLCHAIN=local
	cd /work/app
	go mod init example.com/go-gin
	cat >main.go <<'GO'
package main

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

func main() {
	r := gin.Default()
	r.GET("/ping", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{
			"message": "pong",
		})
	})
	r.Run() // listen and serve on 0.0.0.0:8080 (for windows "localhost:8080")
}
GO
	go get "github.com/gin-gonic/gin@$LR_FIXTURE_GIN"
	go mod tidy
}
