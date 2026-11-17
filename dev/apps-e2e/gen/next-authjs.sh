# Next.js 16 (create-next-app defaults) with Auth.js v5 installed as its
# "Installation" guide says, and the Credentials provider from its
# "Credentials" guide (one fixed demo user instead of a database lookup).
# No AUTH_SECRET: `npx auth secret` writes .env.local, which is git-ignored.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-next-app@$LR_FIXTURE_CREATE_NEXT_APP_16" "$FIXTURE" --yes --use-npm --disable-git
	cd "$FIXTURE"
	npm install "next-auth@$LR_FIXTURE_NEXT_AUTH"
	cat >auth.ts <<'TS'
import NextAuth from "next-auth"
import Credentials from "next-auth/providers/credentials"

export const { handlers, signIn, signOut, auth } = NextAuth({
  providers: [
    Credentials({
      credentials: {
        email: {},
        password: {},
      },
      authorize: async (credentials) => {
        if (credentials.email === "demo@example.com" && credentials.password === "demo") {
          return { id: "1", name: "Demo", email: "demo@example.com" }
        }
        return null
      },
    }),
  ],
})
TS
	mkdir -p 'app/api/auth/[...nextauth]'
	cat >'app/api/auth/[...nextauth]/route.ts' <<'TS'
import { handlers } from "@/auth" // Referring to the auth.ts we just created
export const { GET, POST } = handlers
TS
	cd ..
	adopt "$FIXTURE"
}
