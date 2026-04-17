import { apiClient } from '@farm-erp/shared';
import toast from 'react-hot-toast';

/** Single-flight guard to avoid double-trigger downloads from rapid clicks. */
let downloadInFlight = false;

/**
 * Download a report file (blob). Shows error toast on failure; success toast once when done.
 */
export async function downloadReportBlob(pathWithQuery: string, filename: string): Promise<void> {
  if (downloadInFlight) {
    toast.error('A download is already in progress. Please wait.');
    return;
  }
  downloadInFlight = true;
  try {
    const blob = await apiClient.getBlob(pathWithQuery);
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
    toast.success('Download started');
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Export failed');
    throw e;
  } finally {
    downloadInFlight = false;
  }
}

function q(params: Record<string, string | undefined>): string {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== '') search.append(k, v);
  });
  const s = search.toString();
  return s ? `?${s}` : '';
}

/** Safe segment for filenames (Windows-safe, short). */
export function exportNameSlug(name: string | undefined, projectId: string): string {
  const raw = (name ?? '').trim();
  if (!raw) return projectId.slice(0, 8);
  const slug = raw
    .replace(/[/\\:*?"<>|]+/g, '-')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/[^a-zA-Z0-9-_]/g, '')
    .slice(0, 32);
  return slug || projectId.slice(0, 8);
}

export function buildResponsibilityExportFilename(
  projectId: string,
  projectName: string | undefined,
  from: string,
  to: string,
  format: 'pdf' | 'csv'
): string {
  const slug = exportNameSlug(projectName, projectId);
  return `project-responsibility-${slug}-${from}-to-${to}.${format}`;
}

export function buildHariStatementExportFilename(
  projectId: string,
  projectName: string | undefined,
  upToDate: string,
  format: 'pdf' | 'csv'
): string {
  const slug = exportNameSlug(projectName, projectId);
  return `hari-statement-${slug}-${upToDate}.${format}`;
}

export function buildSettlementReviewExportFilename(
  projectId: string,
  projectName: string | undefined,
  upToDate: string,
  format: 'pdf' | 'csv'
): string {
  const slug = exportNameSlug(projectName, projectId);
  return `settlement-review-${slug}-${upToDate}.${format}`;
}

export function projectResponsibilityExportPath(
  format: 'pdf' | 'csv',
  params: { project_id: string; from: string; to: string; crop_cycle_id?: string }
): string {
  return `/api/reports/project-responsibility/export${q({
    format,
    project_id: params.project_id,
    from: params.from,
    to: params.to,
    crop_cycle_id: params.crop_cycle_id,
  })}`;
}

export function projectPartyEconomicsExportPath(
  format: 'pdf' | 'csv',
  params: { project_id: string; party_id: string; up_to_date: string }
): string {
  return `/api/reports/project-party-economics/export${q({
    format,
    project_id: params.project_id,
    party_id: params.party_id,
    up_to_date: params.up_to_date,
  })}`;
}

export function projectSettlementReviewExportPath(
  format: 'pdf' | 'csv',
  params: {
    project_id: string;
    up_to_date: string;
    responsibility_from?: string;
    responsibility_to?: string;
  }
): string {
  return `/api/reports/project-settlement-review/export${q({
    format,
    project_id: params.project_id,
    up_to_date: params.up_to_date,
    responsibility_from: params.responsibility_from,
    responsibility_to: params.responsibility_to,
  })}`;
}
