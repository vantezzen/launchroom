package state

import (
	"fmt"
	"go/ast"
	"go/constant"
	"go/token"
	"reflect"
	"slices"
	"strings"
	"testing"
)

// TestLimitsMatchSchema: a limit is stated twice, in the field's jsonschema
// tag (the published schema the dashboard validates against) and in the
// Validate code (checkCount/checkText). This test reads both from the
// source and fails when a checked field's tag says something else or says
// nothing, so the two statements can't drift apart.
func TestLimitsMatchSchema(t *testing.T) {
	fset, files := parseState(t)

	consts := map[string]constant.Value{}
	var pending []*ast.ValueSpec
	for _, f := range files {
		ast.Inspect(f, func(n ast.Node) bool {
			if g, ok := n.(*ast.GenDecl); ok && g.Tok == token.CONST {
				for _, s := range g.Specs {
					pending = append(pending, s.(*ast.ValueSpec))
				}
			}
			return true
		})
	}
	for range 4 { // constants may refer to later ones
		for _, vs := range pending {
			for i, n := range vs.Names {
				if i < len(vs.Values) {
					if v := evalInt(vs.Values[i], consts); v != nil {
						consts[n.Name] = v
					}
				}
			}
		}
	}

	// structs: type name -> field name -> (type, jsonschema tag)
	structs := map[string]map[string]limitField{}
	for _, f := range files {
		for _, d := range f.Decls {
			g, ok := d.(*ast.GenDecl)
			if !ok || g.Tok != token.TYPE {
				continue
			}
			for _, spec := range g.Specs {
				ts := spec.(*ast.TypeSpec)
				st, ok := ts.Type.(*ast.StructType)
				if !ok {
					continue
				}
				fs := map[string]limitField{}
				for _, fl := range st.Fields.List {
					var tag string
					if fl.Tag != nil {
						tag = reflect.StructTag(strings.Trim(fl.Tag.Value, "`")).Get("jsonschema")
					}
					for _, n := range fl.Names {
						fs[n.Name] = limitField{fl.Type, tag}
					}
					if len(fl.Names) == 0 { // embedded
						if id := typeName(fl.Type); id != "" {
							for k, v := range structs[id] {
								fs[k] = v
							}
						}
					}
				}
				structs[ts.Name.Name] = fs
			}
		}
	}
	// exprType is the named type of e in scope (pointers, slices and maps
	// resolve to their element), "" when unknown.
	var exprType func(e ast.Expr, scope map[string]string) string
	exprType = func(e ast.Expr, scope map[string]string) string {
		switch x := e.(type) {
		case *ast.Ident:
			return scope[x.Name]
		case *ast.ParenExpr:
			return exprType(x.X, scope)
		case *ast.UnaryExpr:
			return exprType(x.X, scope)
		case *ast.StarExpr:
			return exprType(x.X, scope)
		case *ast.IndexExpr:
			return exprType(x.X, scope)
		case *ast.SelectorExpr:
			if f, ok := structs[exprType(x.X, scope)][x.Sel.Name]; ok {
				return typeName(f.typ)
			}
		}
		return ""
	}

	checked := 0
	for _, f := range files {
		for _, d := range f.Decls {
			fd, ok := d.(*ast.FuncDecl)
			if !ok || fd.Body == nil {
				continue
			}
			scope := map[string]string{}
			if fd.Recv != nil {
				for _, r := range fd.Recv.List {
					for _, n := range r.Names {
						scope[n.Name] = typeName(r.Type)
					}
				}
			}
			for _, prm := range fd.Type.Params.List {
				for _, n := range prm.Names {
					scope[n.Name] = typeName(prm.Type)
				}
			}
			ast.Inspect(fd.Body, func(n ast.Node) bool {
				switch x := n.(type) {
				case *ast.AssignStmt:
					if x.Tok == token.DEFINE && len(x.Lhs) == len(x.Rhs) {
						for i, l := range x.Lhs {
							if id, ok := l.(*ast.Ident); ok {
								if t := exprType(x.Rhs[i], scope); t != "" {
									scope[id.Name] = t
								}
							}
						}
					}
				case *ast.RangeStmt:
					if id, ok := x.Value.(*ast.Ident); ok {
						if t := exprType(x.X, scope); t != "" {
							scope[id.Name] = t
						}
					}
				case *ast.CallExpr:
					if ok, err := compareLimit(x, scope, structsTag(structs, exprType), consts); ok {
						checked++
						if err != "" {
							t.Errorf("%s: %s", fset.Position(x.Pos()), err)
						}
					}
				}
				return true
			})
		}
	}
	if checked < 100 {
		t.Fatalf("compared only %d limits; the parser no longer sees them", checked)
	}
}

// contextLimits are stricter limits of a type in one place; its schema
// states the general one.
var contextLimits = map[string]bool{
	"LogLine.Text=2048": true, // WorkloadEvent.output: the last lines before an exit (maxEventOutputLine)
}

type limitField struct {
	typ ast.Expr
	tag string
}

// structsTag looks up the jsonschema tag of field sel in scope ("", false: unknown).
func structsTag(structs map[string]map[string]limitField, exprType func(ast.Expr, map[string]string) string) func(*ast.SelectorExpr, map[string]string) (string, string, bool) {
	return func(sel *ast.SelectorExpr, scope map[string]string) (string, string, bool) {
		typ := exprType(sel.X, scope)
		f, ok := structs[typ][sel.Sel.Name]
		return typ + "." + sel.Sel.Name, f.tag, ok
	}
}

// compareLimit compares a checkCount(e, path, len(x.F), min, max) or
// checkText(e, path, x.F, max) call with F's jsonschema tag. ok is false
// for other calls and fields of unknown type; err describes a mismatch.
func compareLimit(call *ast.CallExpr, scope map[string]string, tagOf func(*ast.SelectorExpr, map[string]string) (string, string, bool), consts map[string]constant.Value) (ok bool, err string) {
	fn, isIdentFn := call.Fun.(*ast.Ident)
	if !isIdentFn {
		return false, ""
	}
	var subject, limit ast.Expr
	key := "maxLength"
	switch {
	case fn.Name == "checkCount" && len(call.Args) == 5:
		l, isCall := call.Args[2].(*ast.CallExpr)
		if !isCall || !isIdent(l.Fun, "len") {
			return false, ""
		}
		subject, limit, key = l.Args[0], call.Args[4], "maxItems"
	case fn.Name == "checkText" && len(call.Args) == 4:
		subject, limit = call.Args[2], call.Args[3]
	default:
		return false, ""
	}
	for {
		c, isCall := subject.(*ast.CallExpr)
		if !isCall || len(c.Args) != 1 {
			break
		}
		subject = c.Args[0] // string(x.F)
	}
	sel, isSel := subject.(*ast.SelectorExpr)
	if !isSel {
		return false, ""
	}
	name, tag, known := tagOf(sel, scope)
	v := evalInt(limit, consts)
	if !known || v == nil || v.ExactString() == "0" {
		// Unknown type, a limit that depends on something else (the task
		// types this server runs) or a conditional "must be empty".
		return false, ""
	}
	if contextLimits[name+"="+v.ExactString()] {
		return true, ""
	}
	if got := tagValue(tag, key); got != v.ExactString() {
		return true, fmt.Sprintf("%s is checked against %s, but its jsonschema tag has %s=%q", name, v.ExactString(), key, got)
	}
	return true, ""
}

func typeName(e ast.Expr) string {
	switch x := e.(type) {
	case *ast.Ident:
		return x.Name
	case *ast.StarExpr:
		return typeName(x.X)
	case *ast.ArrayType:
		return typeName(x.Elt)
	case *ast.MapType:
		return typeName(x.Value)
	}
	return ""
}

func tagValue(tag, key string) string {
	for _, part := range strings.Split(strings.ReplaceAll(tag, `\,`, "\x00"), ",") {
		if v, ok := strings.CutPrefix(part, key+"="); ok {
			return v
		}
	}
	return ""
}

func evalInt(e ast.Expr, consts map[string]constant.Value) constant.Value {
	switch x := e.(type) {
	case *ast.BasicLit:
		if x.Kind == token.INT {
			return constant.MakeFromLiteral(x.Value, x.Kind, 0)
		}
	case *ast.Ident:
		return consts[x.Name]
	case *ast.ParenExpr:
		return evalInt(x.X, consts)
	case *ast.BinaryExpr:
		a, b := evalInt(x.X, consts), evalInt(x.Y, consts)
		if a == nil || b == nil || !slices.Contains([]token.Token{token.ADD, token.SUB, token.MUL, token.QUO, token.SHL}, x.Op) {
			return nil
		}
		if x.Op == token.SHL {
			s, _ := constant.Uint64Val(b)
			return constant.Shift(a, token.SHL, uint(s))
		}
		if x.Op == token.QUO {
			return constant.BinaryOp(a, token.QUO_ASSIGN, b)
		}
		return constant.BinaryOp(a, x.Op, b)
	}
	return nil
}
