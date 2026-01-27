/**
 * Format a date string (YYYY-MM-DD) to YYYYMMDD for filenames
 */
function formatDateForFilename(date: string): string {
  return date.replace(/-/g, '')
}

/**
 * Generate Terrava-branded CSV filename
 * Format: Terrava_<ReportName>_<FromDate>-<ToDate>_<YYYYMMDD>.csv
 * Or: Terrava_<ReportName>_AsOf<Date>_<YYYYMMDD>.csv for single date reports
 */
function generateTerravaFilename(
  reportName: string,
  fromDate?: string,
  toDate?: string,
  asOfDate?: string
): string {
  const today = new Date().toISOString().split('T')[0]
  const todayFormatted = formatDateForFilename(today)
  
  let filename = `Terrava_${reportName}`
  
  if (asOfDate) {
    const asOfFormatted = formatDateForFilename(asOfDate)
    filename += `_AsOf${asOfFormatted}`
  } else if (fromDate && toDate) {
    const fromFormatted = formatDateForFilename(fromDate)
    const toFormatted = formatDateForFilename(toDate)
    filename += `_${fromFormatted}-${toFormatted}`
  }
  
  filename += `_${todayFormatted}.csv`
  
  return filename
}

/**
 * Helper function to export data to CSV
 * @param data - Array of objects to export
 * @param filename - Filename (or use Terrava format if reportName provided)
 * @param headers - Optional array of header names
 * @param options - Optional: reportName, fromDate, toDate for Terrava filename format
 */
export function exportToCSV<T extends object>(
  data: T[],
  filename: string,
  headers?: string[],
  options?: {
    reportName?: string
    fromDate?: string
    toDate?: string
  }
): void {
  if (data.length === 0) {
    alert('No data to export')
    return
  }

  // Use Terrava filename format if reportName is provided
  const finalFilename = options?.reportName
    ? generateTerravaFilename(options.reportName, options.fromDate, options.toDate, options.asOfDate)
    : filename

  // Get headers from first object if not provided
  const csvHeaders = headers || (Object.keys(data[0] as object) as string[])

  // Create CSV content
  const csvRows: string[] = []

  // Add header row
  csvRows.push(csvHeaders.map(h => escapeCSV(h)).join(','))

  // Add data rows
  data.forEach(row => {
    const values = csvHeaders.map(header => {
      const value = (row as Record<string, unknown>)[header]
      return escapeCSV(value != null ? String(value) : '')
    })
    csvRows.push(values.join(','))
  })
  
  // Create blob and download
  const csvContent = csvRows.join('\n')
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  const url = URL.createObjectURL(blob)
  
  link.setAttribute('href', url)
  link.setAttribute('download', finalFilename)
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function escapeCSV(value: string): string {
  // If value contains comma, newline, or quote, wrap in quotes and escape quotes
  if (value.includes(',') || value.includes('\n') || value.includes('"')) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}
