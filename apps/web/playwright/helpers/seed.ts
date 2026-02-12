import * as fs from 'fs';
import * as path from 'path';

export function getSeed<T = Record<string, unknown>>(): T {
  const seedPath = path.join(process.cwd(), 'playwright', '.auth', 'seed.json');
  const raw = fs.readFileSync(seedPath, 'utf-8');
  return JSON.parse(raw) as T;
}
