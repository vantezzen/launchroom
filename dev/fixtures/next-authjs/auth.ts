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
