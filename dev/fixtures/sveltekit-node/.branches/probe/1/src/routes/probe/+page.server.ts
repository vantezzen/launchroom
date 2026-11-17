// Diagnostic only (probe branch): the client address and URL the server
// sees, and a form action.
import type { Actions, PageServerLoad } from './$types';

export const load: PageServerLoad = ({ getClientAddress, url }) => ({
	clientAddress: getClientAddress(),
	url: url.href
});

export const actions: Actions = {
	default: async ({ request }) => {
		const data = await request.formData();
		return { echoed: String(data.get('value') ?? '') };
	}
};
