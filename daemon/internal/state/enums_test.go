package state

import (
	"go/ast"
	"go/parser"
	"go/token"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// TestEnumListsComplete: every enum's value list (the slice its Validate
// and schema use) names every constant of its type, so a constant added
// without its list entry can't make Validate refuse a valid value.
func TestEnumListsComplete(t *testing.T) {
	_, files := parseState(t)
	constType := map[string]string{} // constant -> its declared type
	lists := map[string][]string{}   // value list -> the constants it names
	for _, f := range files {
		for _, d := range f.Decls {
			g, ok := d.(*ast.GenDecl)
			if !ok {
				continue
			}
			for _, spec := range g.Specs {
				vs, ok := spec.(*ast.ValueSpec)
				if !ok {
					continue
				}
				if g.Tok == token.CONST {
					if id, ok := vs.Type.(*ast.Ident); ok {
						for _, n := range vs.Names {
							constType[n.Name] = id.Name
						}
					}
					continue
				}
				if g.Tok != token.VAR || len(vs.Values) != 1 || len(vs.Names) != 1 {
					continue
				}
				lit, ok := vs.Values[0].(*ast.CompositeLit)
				if !ok {
					continue
				}
				if at, ok := lit.Type.(*ast.ArrayType); !ok || at.Len != nil || !isIdent(at.Elt, "string") {
					continue
				}
				var names []string
				for _, el := range lit.Elts {
					call, ok := el.(*ast.CallExpr)
					if !ok || !isIdent(call.Fun, "string") || len(call.Args) != 1 {
						names = nil
						break
					}
					arg, ok := call.Args[0].(*ast.Ident)
					if !ok {
						names = nil
						break
					}
					names = append(names, arg.Name)
				}
				if len(names) > 0 {
					lists[vs.Names[0].Name] = names
				}
			}
		}
	}
	if len(lists) < 40 {
		t.Fatalf("found only %d enum value lists; the parser no longer sees them", len(lists))
	}
	for list, names := range lists {
		typ := constType[names[0]]
		for _, n := range names {
			if constType[n] != typ {
				t.Errorf("%s mixes %s (%s) and %s (%s)", list, names[0], typ, n, constType[n])
			}
		}
		for c, ct := range constType {
			if ct == typ && !slices.Contains(names, c) {
				t.Errorf("%s (%s) is missing from %s", c, typ, list)
			}
		}
	}
}

func parseState(t *testing.T) (*token.FileSet, []*ast.File) {
	t.Helper()
	names, err := filepath.Glob("*.go")
	if err != nil {
		t.Fatal(err)
	}
	fset := token.NewFileSet()
	var files []*ast.File
	for _, n := range names {
		if strings.HasSuffix(n, "_test.go") {
			continue
		}
		f, err := parser.ParseFile(fset, n, nil, 0)
		if err != nil {
			t.Fatal(err)
		}
		files = append(files, f)
	}
	return fset, files
}

func isIdent(e ast.Expr, name string) bool {
	id, ok := e.(*ast.Ident)
	return ok && id.Name == name
}
