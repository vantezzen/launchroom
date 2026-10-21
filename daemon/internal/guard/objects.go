package guard

import (
	"net/http"
	"net/netip"
	"reflect"
	"regexp"
	"strings"

	"github.com/moby/moby/api/types/network"
	"github.com/moby/moby/api/types/volume"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

var (
	reNetworkName = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9_.-]{0,120}$`)
	reVolumeName  = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9_.-]{0,120}$`)
	reImportRepo  = regexp.MustCompile(`^(lr-[a-z0-9][a-z0-9-]{0,62}|launchroom-local/volume-helper)(:[A-Za-z0-9][A-Za-z0-9._-]{0,63})?$`)
	reDigestTag   = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)
	reImportTag   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)
	// Dockerfile instructions an image import may apply (no RUN, ONBUILD, …).
	reChange = regexp.MustCompile(`^(CMD|ENTRYPOINT|LABEL|ENV|USER|WORKDIR|EXPOSE) `)
)

// Address ranges a launchroom network may not use (they would shadow the
// mesh, loopback, link-local/metadata or multicast).
var reservedRanges = []netip.Prefix{
	policy.MeshRange,
	netip.MustParsePrefix("127.0.0.0/8"),
	netip.MustParsePrefix("169.254.0.0/16"),
	netip.MustParsePrefix("224.0.0.0/4"),
	netip.MustParsePrefix("0.0.0.0/8"),
}

func (s *Server) createNetwork(w http.ResponseWriter, r *http.Request, q *request) error {
	var req network.CreateRequest
	if err := decodeStrict(r, &req); err != nil {
		return err
	}
	if f := nonZeroOutside(reflect.ValueOf(req), []string{"Name", "Driver", "EnableIPv6", "IPAM", "Labels"}); len(f) > 0 {
		return deny("network fields %s are not allowed", strings.Join(f, ", "))
	}
	switch {
	case !reNetworkName.MatchString(req.Name):
		return deny("network name %q is not generated", truncate(req.Name, 64))
	case !isManaged(req.Labels):
		return deny("networks must carry launchroom.managed=true and a valid launchroom.server label")
	case req.Driver != "bridge":
		return deny("only bridge networks are allowed")
	case req.EnableIPv6 != nil && *req.EnableIPv6:
		return deny("IPv6 networks are not allowed")
	case req.IPAM == nil || (req.IPAM.Driver != "" && req.IPAM.Driver != "default") || len(req.IPAM.Config) != 1 || len(req.IPAM.Options) > 0:
		return deny("networks need the default IPAM driver with exactly one subnet")
	}
	c := req.IPAM.Config[0]
	if f := nonZeroOutside(reflect.ValueOf(c), []string{"Subnet"}); len(f) > 0 {
		return deny("IPAM fields %s are not allowed", strings.Join(f, ", "))
	}
	if !c.Subnet.IsValid() || !c.Subnet.Addr().Is4() || c.Subnet.Bits() < 8 || c.Subnet.Masked() != c.Subnet {
		return deny("subnet %s is not an IPv4 network prefix", c.Subnet)
	}
	for _, p := range reservedRanges {
		if p.Overlaps(c.Subnet) {
			return deny("subnet %s overlaps reserved range %s", c.Subnet, p)
		}
	}
	body, err := encode(req)
	if err != nil {
		return err
	}
	return s.forward(w, r, q, q.apiPath("/networks/create"), nil, body, "application/json")
}

// connectNetwork attaches or detaches a launchroom container to/from a
// launchroom network (the ambassador's aliases, docs/05 §3).
func (s *Server) connectNetwork(w http.ResponseWriter, r *http.Request, q *request) error {
	ctx := r.Context()
	n, _, err := s.inspectNetwork(ctx, q, q.id)
	if err != nil {
		return err
	}
	if !isManaged(n.Labels) {
		return notManaged("network", q.id)
	}
	var target string
	var body any
	if q.op == opNetworkConnect {
		var req network.ConnectRequest
		if err := decodeStrict(r, &req); err != nil {
			return err
		}
		if ep := req.EndpointConfig; ep != nil {
			if f := nonZeroOutside(reflect.ValueOf(*ep), []string{"Aliases"}); len(f) > 0 {
				return deny("endpoint fields %s are not allowed", strings.Join(f, ", "))
			}
			for _, a := range ep.Aliases {
				if !reObject.MatchString(a) {
					return deny("alias %q is not allowed", truncate(a, 64))
				}
			}
		}
		target, body = req.Container, &req
	} else {
		var req network.DisconnectRequest
		if err := decodeStrict(r, &req); err != nil {
			return err
		}
		target, body = req.Container, &req
	}
	if !reObject.MatchString(target) {
		return deny("container %q is not allowed", truncate(target, 64))
	}
	c, _, err := s.inspectContainer(ctx, q, target)
	if err != nil {
		return err
	}
	if c.kind() != kindServer {
		return notManaged("container", target)
	}
	if cls := c.class(); cls != classAmbassador && cls != classWorkload {
		return deny("%s is not attached to networks at runtime", c.Name)
	}
	if c.class() == classWorkload && strings.HasPrefix(n.Name, policy.SystemPrefix) {
		return deny("workloads cannot join system network %s", n.Name)
	}
	// The checked id, not the name it was given.
	switch v := body.(type) {
	case *network.ConnectRequest:
		v.Container = c.ID
	case *network.DisconnectRequest:
		v.Container = c.ID
	}
	enc, err := encode(body)
	if err != nil {
		return err
	}
	suffix := "/connect"
	if q.op == opNetworkDisconnect {
		suffix = "/disconnect"
	}
	return s.forward(w, r, q, q.apiPath("/networks/"+n.ID+suffix), nil, enc, "application/json")
}

func (s *Server) createVolume(w http.ResponseWriter, r *http.Request, q *request) error {
	var req volume.CreateRequest
	if err := decodeStrict(r, &req); err != nil {
		return err
	}
	if f := nonZeroOutside(reflect.ValueOf(req), []string{"Name", "Driver", "Labels"}); len(f) > 0 {
		return deny("volume fields %s are not allowed (driver options can bind host paths)", strings.Join(f, ", "))
	}
	switch {
	case !reVolumeName.MatchString(req.Name):
		return deny("volume name %q is not generated", truncate(req.Name, 64))
	case !isManaged(req.Labels):
		return deny("volumes must carry launchroom.managed=true and a valid launchroom.server label")
	case req.Driver != "" && req.Driver != "local":
		return deny("only local volumes are allowed")
	}
	body, err := encode(req)
	if err != nil {
		return err
	}
	return s.forward(w, r, q, q.apiPath("/volumes/create"), nil, body, "application/json")
}

// imageCreate is a digest-pinned pull, or an import of launchroomd's own
// local images (the ambassador, the volume helper) from a tar stream.
func (s *Server) imageCreate(w http.ResponseWriter, r *http.Request, q *request) error {
	v := q.query
	if v.Get("fromSrc") == "" {
		for _, k := range []string{"repo", "changes", "message"} {
			if v.Has(k) {
				return deny("pull: %s is not allowed", k)
			}
		}
		ref := v.Get("fromImage") + "@" + v.Get("tag")
		if !reDigestTag.MatchString(v.Get("tag")) || state.ImageRef(ref).Validate() != nil {
			return deny("pull %q: images are pulled by digest (registry/repository@sha256:…)", truncate(ref, 128))
		}
		return s.forward(w, r, q, q.apiPath("/images/create"), v, nil, "")
	}
	if v.Has("fromImage") {
		return deny("import: fromImage is not allowed")
	}
	if !reImportRepo.MatchString(v.Get("repo")) {
		return deny("import: %q is not one of launchroomd's local images", truncate(v.Get("repo"), 64))
	}
	if t := v.Get("tag"); t != "" && !reImportTag.MatchString(t) {
		return deny("import: tag %q is not allowed", truncate(t, 64))
	}
	for _, c := range v["changes"] {
		if !reChange.MatchString(c) || strings.ContainsAny(c, "\n\r") {
			return deny("import: change %q is not allowed", truncate(c, 64))
		}
	}
	return s.forward(w, r, q, q.apiPath("/images/create"), v, r.Body, "application/x-tar")
}

// imageRemove removes only images launchroom pulled from an allowed
// registry or built locally (lr-…), never forced.
func (s *Server) imageRemove(w http.ResponseWriter, r *http.Request, q *request) error {
	ref := q.id
	ok := strings.HasPrefix(ref, "lr-") || strings.HasPrefix(ref, "launchroom-local/")
	for _, p := range s.Policy().AllowedRegistries {
		if strings.HasPrefix(ref, p) {
			ok = true
		}
	}
	if !ok {
		return deny("image %s is not one of launchroom's images", truncate(ref, 96))
	}
	return s.forward(w, r, q, q.apiPath("/images/"+ref), q.query, nil, "")
}
