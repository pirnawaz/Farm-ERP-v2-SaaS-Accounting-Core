interface FormFieldProps {
  label: string;
  error?: string;
  required?: boolean;
  children: React.ReactNode;
}

export function FormField({ label, error, required, children }: FormFieldProps) {
  return (
    <div className="mb-4">
      <label className="block text-sm font-medium text-gray-700 mb-1">
        {label}
        {required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <div className="[&_input]:rounded-lg [&_input]:border-gray-300 [&_input]:focus:ring-[#1F6F5C] [&_input]:focus:border-[#1F6F5C] [&_select]:rounded-lg [&_select]:border-gray-300 [&_select]:focus:ring-[#1F6F5C] [&_select]:focus:border-[#1F6F5C]">
        {children}
      </div>
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  );
}
