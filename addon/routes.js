import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('settings', { path: '/' });
    this.route('actions');
    this.route('activity');
    this.route('connection');
});
