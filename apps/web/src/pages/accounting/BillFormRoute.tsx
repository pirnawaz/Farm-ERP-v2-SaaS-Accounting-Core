import { useParams } from 'react-router-dom';
import BillFormPage, { type BillFormMode } from './BillFormPage';

export function BillFormNewRoute(props: { mode: BillFormMode }) {
  return <BillFormPage mode={props.mode} isNew invoiceId={undefined} />;
}

export function BillFormEditRoute(props: { mode: BillFormMode }) {
  const { id } = useParams<{ id: string }>();
  return <BillFormPage mode={props.mode} isNew={false} invoiceId={id} />;
}

