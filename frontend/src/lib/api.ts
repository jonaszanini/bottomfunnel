const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:5080";

export function getToken() {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("bf_token");
}

export function setToken(token: string) {
  localStorage.setItem("bf_token", token);
}

export function clearToken() {
  localStorage.removeItem("bf_token");
}

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (options.body && !(options.body instanceof FormData) && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const res = await fetch(`${API_URL}${path}`, { ...options, headers });
  if (res.status === 401 && typeof window !== "undefined" && !path.startsWith("/auth/login")) {
    clearToken();
    window.location.href = "/login";
  }
  if (!res.ok) {
    let message = res.statusText;
    try {
      const body = await res.json();
      message = body.message ?? message;
    } catch {
      /* ignore */
    }
    throw new Error(message);
  }
  if (res.status === 204 || res.status === 202) return undefined as T;
  return (await res.json()) as T;
}

export const publicApi = API_URL;
