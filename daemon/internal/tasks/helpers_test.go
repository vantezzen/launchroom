package tasks

import "github.com/moby/moby/api/types/container"

func configWith(labels map[string]string) *container.Config {
	return &container.Config{Image: "img", Labels: labels}
}
