import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { TraceabilityPanel } from './TraceabilityPanel';

describe('TraceabilityPanel overlap notice', () => {
  it('renders overlap notice when overlap signals present', () => {
    render(
      <BrowserRouter>
        <TraceabilityPanel
          traceability={{
            overlap_signals: {
              stock_movements_count: 1,
              machinery_lines_from_machine_usage_count: 0,
              machinery_lines_from_machinery_charge_count: 0,
              note: 'Signals are read-only.',
            },
          }}
        />
      </BrowserRouter>,
    );

    expect(screen.getByText(/Notice: linked downstream records exist/i)).toBeInTheDocument();
    expect(screen.getByText(/Avoid recording the same real-world event again/i)).toBeInTheDocument();
  });
});

