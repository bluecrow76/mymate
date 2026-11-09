/**
 * Links that open a device's management IP in another tool - shared by the device inspector and
 * the map's right-click menu so the two always offer the same thing.
 *
 * An IPv6 address needs brackets inside a URL (`https://[2001:db8::1]`), otherwise its colons
 * read as a port. Winbox's own scheme is left bare, as Winbox takes the address as it is.
 */
const urlHost = (ip: string): string => (ip.includes(':') ? `[${ip}]` : ip);

export const winboxUrl = (ip: string): string => `winbox://${ip}`;
export const sshUrl = (ip: string): string => `ssh://${urlHost(ip)}`;
export const httpUrl = (ip: string): string => `http://${urlHost(ip)}`;
export const httpsUrl = (ip: string): string => `https://${urlHost(ip)}`;
