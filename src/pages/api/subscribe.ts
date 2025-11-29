import type { APIRoute } from 'astro';

export const prerender = false;

interface SubscribeRequest {
	firstName: string;
	phone: string;
	postcode: string;
}

interface KlaviyoProfile {
	type: 'profile';
	attributes: {
		phone_number: string;
		first_name: string;
		location?: {
			zip: string;
			country: string;
		};
		properties?: Record<string, unknown>;
	};
}

export const POST: APIRoute = async ({ request }) => {
	try {
		const data: SubscribeRequest = await request.json();
		
		// Validate required fields
		if (!data.firstName || !data.phone || !data.postcode) {
			return new Response(
				JSON.stringify({ 
					success: false, 
					error: 'Missing required fields' 
				}),
				{ 
					status: 400,
					headers: { 'Content-Type': 'application/json' }
				}
			);
		}

		// Validate postcode format (Australian 4-digit)
		if (!/^\d{4}$/.test(data.postcode)) {
			return new Response(
				JSON.stringify({ 
					success: false, 
					error: 'Invalid postcode format' 
				}),
				{ 
					status: 400,
					headers: { 'Content-Type': 'application/json' }
				}
			);
		}

		// Format phone number for Klaviyo (E.164 format)
		let formattedPhone = data.phone.replace(/\s+/g, '').replace(/[^\d+]/g, '');
		if (formattedPhone.startsWith('0')) {
			formattedPhone = '+61' + formattedPhone.slice(1);
		} else if (!formattedPhone.startsWith('+')) {
			formattedPhone = '+61' + formattedPhone;
		}

		const apiKey = import.meta.env.KLAVIYO_API_KEY;
		const listId = import.meta.env.KLAVIYO_LIST_ID;

		if (!apiKey || !listId) {
			console.error('Missing Klaviyo configuration');
			return new Response(
				JSON.stringify({ 
					success: false, 
					error: 'Server configuration error' 
				}),
				{ 
					status: 500,
					headers: { 'Content-Type': 'application/json' }
				}
			);
		}

		// Step 1: Create or update the profile
		const profilePayload: { data: KlaviyoProfile } = {
			data: {
				type: 'profile',
				attributes: {
					phone_number: formattedPhone,
					first_name: data.firstName,
					location: {
						zip: data.postcode,
						country: 'Australia'
					},
					properties: {
						postcode: data.postcode,
						source: 'Ferguson Livestock Website',
						signup_date: new Date().toISOString()
					}
				}
			}
		};

		const profileResponse = await fetch('https://a.klaviyo.com/api/profiles/', {
			method: 'POST',
			headers: {
				'Authorization': `Klaviyo-API-Key ${apiKey}`,
				'Content-Type': 'application/json',
				'revision': '2024-02-15'
			},
			body: JSON.stringify(profilePayload)
		});

		let profileId: string;

		if (profileResponse.status === 201) {
			// New profile created
			const profileData = await profileResponse.json();
			profileId = profileData.data.id;
		} else if (profileResponse.status === 409) {
			// Profile already exists - get the existing profile ID from the error response
			const errorData = await profileResponse.json();
			const existingId = errorData.errors?.[0]?.meta?.duplicate_profile_id;
			
			if (existingId) {
				profileId = existingId;
				
				// Update the existing profile with new data
				await fetch(`https://a.klaviyo.com/api/profiles/${profileId}/`, {
					method: 'PATCH',
					headers: {
						'Authorization': `Klaviyo-API-Key ${apiKey}`,
						'Content-Type': 'application/json',
						'revision': '2024-02-15'
					},
					body: JSON.stringify({
						data: {
							type: 'profile',
							id: profileId,
							attributes: {
								first_name: data.firstName,
								location: {
									zip: data.postcode,
									country: 'Australia'
								},
								properties: {
									postcode: data.postcode,
									source: 'Ferguson Livestock Website',
									last_updated: new Date().toISOString()
								}
							}
						}
					})
				});
			} else {
				throw new Error('Could not get existing profile ID');
			}
		} else {
			const errorText = await profileResponse.text();
			console.error('Klaviyo profile creation failed:', errorText);
			throw new Error('Failed to create profile');
		}

		// Step 2: Add profile to the list
		const listResponse = await fetch(`https://a.klaviyo.com/api/lists/${listId}/relationships/profiles/`, {
			method: 'POST',
			headers: {
				'Authorization': `Klaviyo-API-Key ${apiKey}`,
				'Content-Type': 'application/json',
				'revision': '2024-02-15'
			},
			body: JSON.stringify({
				data: [
					{
						type: 'profile',
						id: profileId
					}
				]
			})
		});

		if (!listResponse.ok && listResponse.status !== 204) {
			const errorText = await listResponse.text();
			console.error('Klaviyo list subscription failed:', errorText);
			// Don't throw - profile was still created, they just might already be on the list
		}

		return new Response(
			JSON.stringify({ 
				success: true, 
				message: 'Successfully subscribed to the wait list!' 
			}),
			{ 
				status: 200,
				headers: { 'Content-Type': 'application/json' }
			}
		);

	} catch (error) {
		console.error('Subscribe error:', error);
		return new Response(
			JSON.stringify({ 
				success: false, 
				error: 'An error occurred. Please try again.' 
			}),
			{ 
				status: 500,
				headers: { 'Content-Type': 'application/json' }
			}
		);
	}
};

