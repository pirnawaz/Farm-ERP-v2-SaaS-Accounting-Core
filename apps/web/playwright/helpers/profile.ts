import * as fs from 'fs';
import * as path from 'path';

export function getProfile(): { profile: string } {
  const profilePath = path.join(process.cwd(), 'playwright', '.auth', 'profile.json');
  try {
    const raw = fs.readFileSync(profilePath, 'utf-8');
    return JSON.parse(raw) as { profile: string };
  } catch {
    return { profile: 'core' };
  }
}
