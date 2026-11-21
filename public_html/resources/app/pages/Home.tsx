import {useParams} from 'react-router';
import {Page} from '../components/PageState';
import {unitById} from '../lib/session';

export default function Home() {
  const unit = unitById(Number(useParams().unit));
  return <Page title={unit ? unit.name : 'Home'} description="Today at a glance">{null}</Page>;
}
