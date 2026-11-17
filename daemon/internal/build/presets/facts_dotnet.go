package presets

import (
	"io/fs"
	"path"
	"strings"
)

// DotNetFacts are read from the project files in the app root.
type DotNetFacts struct {
	// Project is the first *.csproj/*.fsproj in the app root.
	Project string
	// Web is set when the project uses the ASP.NET Core web SDK.
	Web bool
}

func gatherDotNet(fsys fs.FS, f *Facts) {
	entries, err := fs.ReadDir(fsys, ".")
	if err != nil {
		return
	}
	for _, e := range entries {
		if ext := path.Ext(e.Name()); e.Type().IsRegular() && (ext == ".csproj" || ext == ".fsproj") {
			f.DotNet.Project = e.Name()
			if s, ok := readSmall(fsys, e.Name()); ok {
				f.DotNet.Web = strings.Contains(s, "Microsoft.NET.Sdk.Web")
			}
			return
		}
	}
}
