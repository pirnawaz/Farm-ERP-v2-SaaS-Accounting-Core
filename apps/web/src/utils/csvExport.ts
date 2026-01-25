/**
 * Helper function to export data to CSV
 */
export function exportToCSV<T extends object>(
  data: T[],
  filename: string,
  headers?: string[]
): void {
  if (data.length === 0) {
    alert('No data to export')
    return
  }

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
  link.setAttribute('download', filename)
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
